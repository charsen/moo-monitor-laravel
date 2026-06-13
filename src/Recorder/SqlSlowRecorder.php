<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Recorder;

use Illuminate\Http\Request;
use Mooeen\Monitor\Concerns\SafelyLogs;
use Mooeen\Monitor\Recorder\Concerns\ManagesBucketedRecords;
use Mooeen\Monitor\Recorder\Concerns\MasksSensitiveUrl;
use Mooeen\Monitor\Recorder\Concerns\TracksDailyCap;
use Mooeen\Monitor\Recorder\Concerns\WritesBucketedYaml;
use Throwable;

/**
 * 慢 SQL 记录器
 *
 * 把 listener 捕到的慢 SQL 落盘到 storage_path('moo-monitor/sql-slows/{open,resolved,deleted}/<hash>.yaml'),
 * 同 hash(normalized sql + file:line)累加 count、刷新 last_seen、保留 max_took_ms。
 *
 * 三桶管理 / 聚合 / 文件 IO 在 ManagesBucketedRecords trait(与 RuntimeErrorRecorder 共用);
 * 这里只留慢 SQL 特有的 record / build / refresh / makeHash / extract* + deriveRow。
 * 数据字段精简到 SQL 场景需要的部分(无 trace / payload / source_snippet)。
 */
class SqlSlowRecorder
{
    use ManagesBucketedRecords;
    use MasksSensitiveUrl;
    use SafelyLogs;
    use TracksDailyCap;
    use WritesBucketedYaml;

    /** open 数缓存 key */
    public const CACHE_OPEN_COUNT = 'moo-monitor:sql_slow:open_count';

    private string $basePath;

    private array $config;

    public function __construct(?string $basePath = null, ?array $config = null)
    {
        $this->config   = $config ?? (array) config('moo-monitor.sql_slow', []);
        $path           = (string) ($this->config['path'] ?? 'moo-monitor/sql-slows');
        $this->basePath = $basePath ?? RuntimeErrorRecorder::resolveStoragePath($path);
    }

    /**
     * 记录一条慢 SQL。返回 hash;失败时 null。
     *
     * @param string $sqlRaw  带 ? 占位的原始 SQL(用于聚合 hash)
     * @param string $sqlLast binding 替换后的 SQL(展示最后一次具体值)
     * @param float  $tookMs  执行毫秒数
     * @param string $file    触发 SQL 的应用文件(绝对路径,内部转 rel)
     * @param int    $line    行号
     */
    public function record(
        string $sqlRaw,
        string $sqlLast,
        float $tookMs,
        string $file,
        int $line,
        ?Request $request = null
    ): ?string {
        if (! ($this->config['enabled'] ?? false)) {
            return null;
        }

        try {
            $request ??= function_exists('request') ? request() : null;
            $hash = $this->makeHash($sqlRaw, $file, $line);
            // 用记录实际所在桶判定(桶目录是 status 真源),而非 yaml 内 status 字段。
            $bucket   = $this->findBucket($hash);
            $existing = $bucket !== null ? $this->readFile($hash, $bucket) : null;
            $now      = $this->nowIso();
            $isNew    = false;

            if ($existing && $bucket !== 'open') {
                // resolved / deleted 桶里的同 hash 复发 → 搬回 open 复活(之前 deleted 漏处理会留跨桶重复)。
                $this->moveFile($hash, $bucket, 'open');
                $existing['status']        = 'open';
                $existing['resolved_at']   = null;
                $existing['resolved_by']   = null;
                $existing['resolved_note'] = null;
                $data                      = $this->refresh($existing, $sqlLast, $tookMs, $file, $line, $request, $now);
            } elseif ($existing) {
                // 当天已达写盘上限 → 直接返回,不写盘(冻结 yaml:不刷 last_seen/不 +count/不刷 meta.updated_at)。
                // 热慢查询冻结后 mtime 不变 → 不再每分钟被 moo:cloud:push 反复推。跟 RuntimeErrorRecorder 对齐。
                if ($this->dailyCapReached($existing, $now)) {
                    return $hash;
                }
                $data = $this->refresh($existing, $sqlLast, $tookMs, $file, $line, $request, $now);
            } else {
                if ($this->openBucketFull()) {
                    return null;
                }
                $data  = $this->build($hash, $sqlRaw, $sqlLast, $tookMs, $file, $line, $request, $now);
                $isNew = true;
            }

            $this->ensureDir();
            if (! $this->writeFile($hash, 'open', $data, $isNew)) {
                $this->logWriteFailure($file, $line, $request);

                return null;
            }

            return $hash;
        } catch (Throwable $self) {
            // safeLog:日志写入本身也可能抛(database/slack 通道后端不可用),否则会逃出 record()
            // → 经 SqlSlowListener 冒泡进宿主查询执行。record() 对调用方只返回 string|null,永不抛。
            $this->safeLog('warning', 'sql-slow-recorder failed: ' . $self->getMessage());

            return null;
        }
    }

    /**
     * 仅在 record() **真正写盘失败**(目录建不出 / 不可写)时记一次诊断 —— 跟 RuntimeErrorRecorder 对齐。
     * disabled / 桶满 这些预期跳过虽也返回 null,但没动盘,不该报错。一次请求只记一次,避免刷屏。
     */
    private function logWriteFailure(string $file, int $line, ?Request $request): void
    {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;

        $openDir = $this->basePath . '/open';
        // url 走 maskUrl,避免把 ?token=/api_key= 明文写进宿主 laravel.log(同 RuntimeErrorRecorder)。
        $this->safeLog('error', 'sql-slow-recorder: 写盘失败(目录不可写?) ' . $openDir, [
            'is_dir'   => is_dir($openDir),
            'writable' => is_writable(is_dir($openDir) ? $openDir : dirname($openDir)),
            'perms'    => is_dir($openDir) ? substr(sprintf('%o', (int) @fileperms($openDir)), -4) : null,
            'owner'    => is_dir($openDir) ? @fileowner($openDir) : null,
            'php_uid'  => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'origin'   => $file . ':' . $line,
            'url'      => $request !== null ? $this->maskUrl($request->fullUrl()) : null,
        ]);
    }

    // ====================================================================
    // 内部:hash / build / refresh
    // ====================================================================

    private function makeHash(string $sqlRaw, string $file, int $line): string
    {
        $rel = $this->relPath($file);
        $sql = $this->normalizeSql($sqlRaw);

        return substr(md5("$rel:$line|$sql"), 0, 12);
    }

    /**
     * 把 SQL 中字面常量 / 多余空白归一,让"同 query 不同 binding"聚合到同一 hash。
     * listener 传进来的 sql_raw 已经是带 ? 的 prepared 形态,主要归一化:
     *   - 数字字面量 → N
     *   - 多空白 → 单空格
     */
    private function normalizeSql(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        // IN (?, ?, ?) 不同长度的占位符列表归一成 (?) —— 否则同一 whereIn 查询因 id 个数不同裂成几十个
        // hash,一条热查询就能把免费版 30 条配额占满、把别的记录挤掉。
        $sql = preg_replace('/\(\s*\?(\s*,\s*\?)*\s*\)/', '(?)', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+\b/', 'N', $sql)                    ?? $sql;

        // hash 前截断放宽到 8192(原 512 易把"前 512 字相同、尾部不同"的两条不同查询误并成一个 hash)。
        return trim(substr($sql, 0, 8192));
    }

    private function build(
        string $hash,
        string $sqlRaw,
        string $sqlLast,
        float $tookMs,
        string $file,
        int $line,
        ?Request $request,
        string $now
    ): array {
        // sql_last 含 binding 替换后的真实值,可能带密钥(WHERE token='…')→ 值侧脱敏后再落盘/上云。
        $maskedLast = $this->maskSecrets($this->maskSensitiveSql($sqlLast));

        return [
            'hash'          => $hash,
            'first_seen'    => $now,
            'last_seen'     => $now,
            'count'         => 1,
            'status'        => 'open',
            'resolved_at'   => null,
            'resolved_by'   => null,
            'resolved_note' => null,
            'sql'           => [
                'raw'  => $this->truncate($sqlRaw, 4096),
                'last' => $this->truncate($maskedLast, 4096),
                // 原始长度(truncate 前)— user 通过对比 strlen vs bytes 一眼判断是否截过
                'raw_bytes'  => $this->strLen($sqlRaw),
                'last_bytes' => $this->strLen($maskedLast),
            ],
            'took' => [
                'last_ms'      => round($tookMs, 2),
                'max_ms'       => round($tookMs, 2),
                'threshold_ms' => (int) ($this->config['threshold_ms'] ?? 100),
            ],
            'at' => [
                'file' => $this->relPath($file),
                'line' => $line,
            ],
            'daily'   => ['date' => $this->today($now), 'count' => 1],
            'context' => $this->extractContext(),
            'request' => $this->extractRequest($request),
        ];
    }

    private function refresh(
        array $existing,
        string $sqlLast,
        float $tookMs,
        string $file,
        int $line,
        ?Request $request,
        string $now
    ): array {
        $maskedLast                    = $this->maskSecrets($this->maskSensitiveSql($sqlLast));
        $existing['last_seen']         = $now;
        $existing['count']             = (int) ($existing['count'] ?? 0) + 1;
        $existing['sql']['last']       = $this->truncate($maskedLast, 4096);
        $existing['sql']['last_bytes'] = $this->strLen($maskedLast);
        $existing['took']['last_ms']   = round($tookMs, 2);
        $prevMax                       = (float) ($existing['took']['max_ms'] ?? 0);
        $existing['took']['max_ms']    = max($prevMax, round($tookMs, 2));
        $existing['at']                = [
            'file' => $this->relPath($file),
            'line' => $line,
        ];
        $existing['context'] = $this->extractContext();
        $existing['request'] = $this->extractRequest($request);
        $existing['daily']   = $this->bumpDaily($existing['daily'] ?? null, $now);

        return $existing;
    }

    private function extractContext(): array
    {
        return [
            'env'         => function_exists('config') ? (string) config('app.env', 'unknown') : 'unknown',
            'project'     => function_exists('config') ? (string) config('app.name', 'unknown') : 'unknown',
            'occurred_at' => $this->nowIso(),
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
            ];
        }

        $user = null;
        try {
            $auth = function_exists('auth') ? auth() : null;
            if ($auth) {
                foreach (['admin', 'user', 'web'] as $g) {
                    try {
                        $u = $auth->guard($g)->user();
                        if ($u !== null) {
                            $user = $u;

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
        ];
    }

    /**
     * 桶名是 status 唯一真源 — 跟 runtime recorder 同设计。
     */
    private function deriveRow(string $bucket, array $data): array
    {
        return [
            'status'         => $bucket,
            'first_seen'     => $data['first_seen'] ?? null,
            'last_seen'      => $data['last_seen']  ?? null,
            'count'          => $data['count']      ?? 1,
            'sql_first_line' => $this->firstLine($data['sql']['raw'] ?? ''),
            // 老 yaml fallback 算 mb_strlen(注意:老 yaml 里 raw 已是 truncate 后,fallback 值偏小但不致命)
            'sql_bytes'    => $data['sql']['raw_bytes']     ?? $this->strLen((string) ($data['sql']['raw'] ?? '')),
            'max_ms'       => $data['took']['max_ms']       ?? 0,
            'last_ms'      => $data['took']['last_ms']      ?? 0,
            'threshold_ms' => $data['took']['threshold_ms'] ?? 0,
            'file'         => $data['at']['file']           ?? '',
            'line'         => $data['at']['line']           ?? 0,
            'url'          => $data['request']['url']       ?? null,
            'method'       => $data['request']['method']    ?? null,
        ];
    }

    private function truncate(string $s, int $max): string
    {
        if (function_exists('mb_strlen') && mb_strlen($s) > $max) {
            return mb_substr($s, 0, $max) . '…';
        }
        if (strlen($s) > $max) {
            return substr($s, 0, $max) . '…';
        }

        return $s;
    }

    private function strLen(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
    }
}
