<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Recorder;

use Illuminate\Http\Request;
use Mooeen\Monitor\Recorder\Concerns\ManagesBucketedRecords;
use Mooeen\Monitor\Recorder\Concerns\MasksSensitiveUrl;
use Mooeen\Monitor\Recorder\Concerns\TracksDailyCap;
use Mooeen\Monitor\Recorder\Concerns\WritesBucketedYaml;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runtime 错误记录器
 *
 * 把 reportable 的真异常落盘到 storage_path('moo-monitor/runtimes/{open,resolved}/{hash}.yaml')。
 * 本地仅作云端推送前的临时缓冲(目录自带 .gitignore,与宿主 git 解耦)。
 *
 * 同 hash（class + file + line + normalized message）累加 count、刷新 last_seen，不创建新文件。
 * resolved → 再触发 → 自动 reopen + count+1。
 *
 * 三桶管理 / 聚合 / 文件 IO 在 ManagesBucketedRecords trait(与 SqlSlowRecorder 共用);
 * 这里只留 runtime 特有的 record / build / refresh / makeHash / extract* + deriveRow。
 *
 * 每日写盘上限（daily_cap，默认 10）：同一 hash 当天复发达到上限后，record() 直接返回 hash
 * 不再写盘 —— 冻结 yaml 后文件无 diff(也不再每分钟被 moo:cloud:push 反复推);次日 daily 翻篇归零。
 */
class RuntimeErrorRecorder
{
    use ManagesBucketedRecords;
    use MasksSensitiveUrl;
    use TracksDailyCap;
    use WritesBucketedYaml;

    /** open 数缓存 key(调用方展示徽章/统计也用这个常量) */
    public const CACHE_OPEN_COUNT = 'moo-monitor:runtime:open_count';

    private string $basePath;

    private array $config;

    public function __construct(?string $basePath = null, ?array $config = null)
    {
        $this->config   = $config ?? (array) config('moo-monitor.runtime', []);
        $path           = (string) ($this->config['path'] ?? 'moo-monitor/runtimes');
        $this->basePath = $basePath ?? self::resolveStoragePath($path);
    }

    /** 配置路径解析:绝对路径原样用;相对路径挂在 storage_path() 下(无 Laravel 环境时原样) */
    public static function resolveStoragePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return function_exists('storage_path') ? storage_path($path) : $path;
    }

    /**
     * 记录一条异常。返回 hash；被过滤或失败时返回 null。
     */
    public function record(Throwable $e, ?Request $request = null): ?string
    {
        if (! ($this->config['enabled'] ?? true)) {
            return null;
        }

        if (! $this->shouldReport($e)) {
            return null;
        }

        try {
            $request ??= function_exists('request') ? request() : null;
            $hash     = $this->makeHash($e);
            $existing = $this->find($hash);
            $now      = $this->nowIso();

            if ($existing && ($existing['status'] ?? 'open') === 'resolved') {
                // resolved → 再触发 → reopen + count+1
                $this->moveFile($hash, 'resolved', 'open');
                $existing['status']        = 'open';
                $existing['resolved_at']   = null;
                $existing['resolved_by']   = null;
                $existing['resolved_note'] = null;
                $data                      = $this->refresh($existing, $e, $request, $now);
            } elseif ($existing) {
                // 当天已达写盘上限 → 直接返回,不写盘(冻结 yaml,不再产生 git diff → 止住爆仓)
                if ($this->dailyCapReached($existing, $now)) {
                    return $hash;
                }
                $data = $this->refresh($existing, $e, $request, $now);
            } else {
                if ($this->cachedOpenCount() >= (int) ($this->config['max_open'] ?? 500)) {
                    return null;
                }
                $data = $this->build($hash, $e, $request, $now);
            }

            $this->ensureDir();
            if (! $this->writeFile($hash, 'open', $data)) {
                $this->logWriteFailure($e, $request);

                return null;
            }

            return $hash;
        } catch (Throwable $self) {
            if (function_exists('app')) {
                app('log')->warning('runtime-recorder failed: ' . $self->getMessage());
            }

            return null;
        }
    }

    /**
     * 仅在 record() **真正写盘失败**(目录建不出 / 不可写)时记一次诊断。
     * disabled / 被过滤(4xx·dontReport)/ 桶满这些预期跳过虽也返回 null,但没动盘,不该报错。
     * 一次请求只记一次,避免刷屏。
     */
    private function logWriteFailure(Throwable $origin, ?Request $request): void
    {
        static $logged = false;
        if ($logged || ! function_exists('app')) {
            return;
        }
        $logged = true;

        $openDir = $this->basePath . '/open';
        app('log')->error('runtime-recorder: 写盘失败(目录不可写?) ' . $openDir, [
            'is_dir'   => is_dir($openDir),
            'writable' => is_writable(is_dir($openDir) ? $openDir : dirname($openDir)),
            'perms'    => is_dir($openDir) ? substr(sprintf('%o', (int) @fileperms($openDir)), -4) : null,
            'owner'    => is_dir($openDir) ? @fileowner($openDir) : null,
            'php_uid'  => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'origin'   => get_class($origin),
            'url'      => $request?->fullUrl(),
        ]);
    }

    // ====================================================================
    // 内部：dontReport 过滤
    // ====================================================================

    private function shouldReport(Throwable $e): bool
    {
        // 3.1.0:类列表过滤(SKIP_CLASSES)全部下沉 host Laravel `$exceptions->dontReport([...])`,
        // 这里只保留行为判断:HttpException 4xx 不记,5xx 仍记
        if ($e instanceof HttpException) {
            if ($e->getStatusCode() < 500) {
                return false;
            }
        }

        return true;
    }

    // ====================================================================
    // 内部：hash 计算
    // ====================================================================

    private function makeHash(Throwable $e): string
    {
        $class = get_class($e);
        $file  = $this->relPath($e->getFile());
        $line  = $e->getLine();
        $msg   = $this->normalizeMessage($e->getMessage());

        return substr(md5("$class|$file:$line|$msg"), 0, 12);
    }

    /**
     * 规范化 message：数字 → N，引号字符串 → 'X'，便于参数化错误聚合。
     * 例：`1364 Field 'org_id' doesn't have...` → `N Field 'X' doesn't have...`
     */
    private function normalizeMessage(string $msg): string
    {
        $msg = $this->maskSecrets($this->maskSensitiveSql($msg));
        $msg = preg_replace("/'[^']*'/", "'X'", $msg) ?? $msg;
        $msg = preg_replace('/"[^"]*"/', '"X"', $msg) ?? $msg;
        // UUID / 0x 内存地址 / 长 hex(token、hash 片段)→ 占位 —— 否则同一异常因 message 里可变的
        // UUID/地址(ModelNotFound 带 UUID 等)裂成多 hash。须在数字归一之前(否则 UUID 的数字先被吃掉)。
        $msg = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'U', $msg) ?? $msg;
        $msg = preg_replace('/\b0x[0-9a-f]+\b/i', '0xH', $msg)                                            ?? $msg;
        $msg = preg_replace('/\b[0-9a-f]{16,}\b/i', 'H', $msg)                                            ?? $msg;
        $msg = preg_replace('/\d+/', 'N', $msg)                                                           ?? $msg;

        // hash 前截断放宽到 1024(原 256 易把"前缀相同、尾部不同"的两条不同异常误并成一个 hash)。
        return substr($msg, 0, 1024);
    }

    // ====================================================================
    // 内部：构建 / 刷新
    // ====================================================================

    private function build(string $hash, Throwable $e, ?Request $request, string $now): array
    {
        return [
            'hash'           => $hash,
            'first_seen'     => $now,
            'last_seen'      => $now,
            'count'          => 1,
            'daily'          => ['date' => $this->today($now), 'count' => 1],
            'status'         => 'open',
            'resolved_at'    => null,
            'resolved_by'    => null,
            'resolved_note'  => null,
            'exception'      => $this->extractException($e),
            'request'        => $this->extractRequest($request),
            'context'        => $this->extractContext(),
            'trace'          => $this->extractTrace($e),
            'source_snippet' => $this->extractSourceSnippet($e->getFile(), $e->getLine()),
            'payload'        => $this->extractPayload($request),
        ];
    }

    private function refresh(array $existing, Throwable $e, ?Request $request, string $now): array
    {
        $existing['last_seen'] = $now;
        $existing['count']     = (int) ($existing['count'] ?? 0) + 1;
        $existing['daily']     = $this->bumpDaily($existing['daily'] ?? null, $now);
        // 覆盖末次 request / payload / trace（保留 first_seen 不变）
        $existing['exception']      = $this->extractException($e);
        $existing['request']        = $this->extractRequest($request);
        $existing['context']        = $this->extractContext();
        $existing['trace']          = $this->extractTrace($e);
        $existing['source_snippet'] = $this->extractSourceSnippet($e->getFile(), $e->getLine());
        $existing['payload']        = $this->extractPayload($request);

        return $existing;
    }

    private function extractException(Throwable $e): array
    {
        return [
            'class' => get_class($e),
            'code'  => (string) $e->getCode(),
            // QueryException 的 message 嵌着带 binding 值的 SQL(WHERE token='…')→ maskSensitiveSql;
            // 再叠 maskSecrets 兜住应用写进 message 的 JWT / Bearer / 裸密钥(非 SQL 形态)。
            'message' => $this->maskSecrets($this->maskSensitiveSql($e->getMessage())),
            'file'    => $this->relPath($e->getFile()),
            'line'    => $e->getLine(),
        ];
    }

    private function extractRequest(?Request $request): array
    {
        if ($request === null) {
            return [
                'method'    => 'CLI',
                'url'       => null,
                'ip'        => null,
                'user_id'   => null,
                'user_name' => null,
                'guard'     => null,
            ];
        }

        $user  = null;
        $guard = null;
        try {
            $auth = function_exists('auth') ? auth() : null;
            if ($auth) {
                foreach (['admin', 'user', 'web'] as $g) {
                    try {
                        $u = $auth->guard($g)->user();
                        if ($u !== null) {
                            $user  = $u;
                            $guard = $g;
                            break;
                        }
                    } catch (Throwable) {
                        // skip
                    }
                }
            }
        } catch (Throwable) {
            // ignore
        }

        return [
            'method'    => $request->getMethod(),
            'url'       => $this->maskUrl($request->fullUrl()),
            'ip'        => $request->ip(),
            'user_id'   => $user?->getKey() !== null ? (string) $user->getKey() : null,
            'user_name' => $this->extractUserName($user),
            'guard'     => $guard,
        ];
    }

    private function extractContext(): array
    {
        return [
            'env'         => function_exists('config') ? (string) config('app.env', 'unknown') : 'unknown',
            'project'     => function_exists('config') ? (string) config('app.name', 'unknown') : 'unknown',
            'php'         => PHP_VERSION,
            'laravel'     => function_exists('app') ? (string) app()->version() : '',
            'occurred_at' => $this->nowIso(),
        ];
    }

    private function extractTrace(Throwable $e): array
    {
        // 先脱敏再截断:trace 里的函数参数可能含 JWT / 裸密钥;先 mask 避免被截断切断导致漏 mask。
        $full     = $this->maskSecrets($e->getTraceAsString());
        $maxBytes = (int) ($this->config['trace_max_bytes'] ?? 65536);
        if (strlen($full) > $maxBytes) {
            $full = substr($full, 0, $maxBytes) . "\n... (truncated)";
        }

        $appFrames = [];
        foreach ($e->getTrace() as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }
            $rel = $this->relPath($frame['file']);
            if (! str_starts_with($rel, 'app/') && ! str_starts_with($rel, 'routes/')) {
                continue;
            }
            $appFrames[] = [
                'file'     => $rel,
                'line'     => $frame['line'] ?? 0,
                'function' => trim(($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'], ':'),
            ];
            if (count($appFrames) >= 20) {
                break;
            }
        }

        return [
            'full'       => $full,
            'app_frames' => $appFrames,
        ];
    }

    private function extractSourceSnippet(string $file, int $line): array
    {
        $rel = $this->relPath($file);
        if (! is_file($file)) {
            return ['file' => $rel, 'line' => $line, 'language' => 'php', 'code' => ''];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return ['file' => $rel, 'line' => $line, 'language' => 'php', 'code' => ''];
        }
        $n     = (int) ($this->config['snippet_lines'] ?? 10);
        $start = max(0, $line - 1 - $n);
        $end   = min(count($lines) - 1, $line - 1 + $n);
        $slice = [];
        for ($i = $start; $i <= $end; $i++) {
            $slice[] = sprintf('%4d: %s', $i + 1, $lines[$i]);
        }

        return [
            'file'     => $rel,
            'line'     => $line,
            'language' => 'php',
            'code'     => implode("\n", $slice),
        ];
    }

    private function extractPayload(?Request $request): array
    {
        if ($request === null) {
            return [];
        }
        $method = strtoupper($request->getMethod());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }
        try {
            $data = $request->all();
        } catch (Throwable) {
            return [];
        }

        return $this->maskRecursive($data);
    }

    private function maskRecursive($value, int $depth = 0, int &$budget = 5000)
    {
        if ($depth > 5) {
            return '<deep>';
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // 总节点预算:深度有上限,但「扁平超大数组」(几万元素的列表/宽 map)会逐个 mask + truncate,
                // 单条异常 payload 撑爆写盘耗时与 yaml 体积。耗尽预算即截断。
                if (--$budget < 0) {
                    $out['__truncated__'] = '<too many keys>';
                    break;
                }
                $kStr = (string) $k;
                if ($this->shouldMaskKey($kStr)) {
                    $out[$kStr] = '***';

                    continue;
                }
                $out[$kStr] = $this->maskRecursive($v, $depth + 1, $budget);
            }

            return $out;
        }
        if (is_string($value)) {
            $value = $this->maskSecrets($value);
            $cap   = (int) ($this->config['string_truncate'] ?? 200);
            if (strlen($value) > $cap) {
                return substr($value, 0, $cap - 20) . '…<+' . (strlen($value) - $cap + 20) . ' chars>';
            }

            return $value;
        }
        if (is_object($value)) {
            return '<object:' . get_class($value) . '>';
        }
        if (is_resource($value)) {
            return '<resource:' . get_resource_type($value) . '>';
        }

        return $value;
    }

    /**
     * 单条 yaml 派生 list/count/prune 用字段(原 index 持有的字段全在这)。
     * 桶名为 status 唯一真源 — 防 yaml 内 `status` 字段跟落盘位置不同步。
     */
    private function deriveRow(string $bucket, array $data): array
    {
        return [
            'status'             => $bucket,
            'first_seen'         => $data['first_seen']         ?? null,
            'last_seen'          => $data['last_seen']          ?? null,
            'count'              => $data['count']              ?? 1,
            'class'              => $data['exception']['class'] ?? '',
            'message_first_line' => $this->firstLine($data['exception']['message'] ?? ''),
            'file'               => $data['exception']['file'] ?? '',
            'line'               => $data['exception']['line'] ?? 0,
            'url'                => $data['request']['url']    ?? null,
            'method'             => $data['request']['method'] ?? null,
        ];
    }
}
