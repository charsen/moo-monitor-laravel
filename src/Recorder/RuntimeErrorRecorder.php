<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Recorder;

use Illuminate\Http\Request;
use Mooeen\Monitor\SelfTestException;
use Mooeen\Monitor\StorageScope;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runtime 错误记录器
 *
 * 把 reportable 的真异常落盘到 storage_path('moo-monitor/runtimes/{open,resolved}/{hash}.yaml')。
 * 本地仅作云端推送前的临时缓冲（目录自带 .gitignore，与宿主 git 解耦）。
 *
 * 同 hash（class + file + line + normalized message）累加 count、刷新 last_seen，不创建新文件。
 * resolved → 再触发 → 自动 reopen + count+1。
 *
 * 三桶管理 / 聚合 / 文件 IO / 聚合落盘骨架在抽象基类 BucketedYamlRecorder（与 SqlSlowRecorder 共用）；
 * 这里只留 runtime 特有的 record / build / refresh / makeHash / extract* + deriveRow。
 *
 * 每日写盘上限（daily_cap，默认 10）：同一 hash 当天复发达到上限后，record() 直接返回 hash
 * 不再写盘 —— 冻结 yaml 后文件无 diff（也不再每分钟被 moo:cloud:push 反复推）；次日 daily 翻篇归零。
 */
class RuntimeErrorRecorder extends BucketedYamlRecorder
{
    /** open 数缓存 key（调用方展示徽章/统计也用这个常量） */
    public const CACHE_OPEN_COUNT = 'moo-monitor:runtime:open_count';

    public function __construct(?string $basePath = null, ?array $config = null)
    {
        $this->config   = $config ?? (array) config('moo-monitor.runtime', []);
        $path           = (string) ($this->config['path'] ?? 'moo-monitor/runtimes');
        $this->basePath = $basePath ?? self::resolveStoragePath(StorageScope::scopePath($path));
        $this->masker   = new SensitiveMasker((array) ($this->config['mask_keys'] ?? []));
    }

    /** 配置路径解析：绝对路径原样用；相对路径挂在 storage_path() 下（无 Laravel 环境时原样） */
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
    /**
     * @param array<string,mixed> $meta
     */
    public function record(Throwable $e, ?Request $request = null, string $source = 'reportable', array $meta = []): ?string
    {
        if (! ($this->config['enabled'] ?? true)) {
            return null;
        }

        if (! $this->shouldReport($e)) {
            return null;
        }

        try {
            $request ??= $this->resolveRequest();
            $hash = $this->makeHash($e);
            // 危险语境（fatal / 内存逼近上限，矩阵 #7）走 lean path：跳过 file() 整读 / payload 递归脱敏 /
            // trace 五连正则，避免在只剩 32KB 保留内存的 shutdown 兜底里二次 OOM 把这条记录也吞掉。脱敏不降级。
            $lean = $this->isDangerousContext($e);

            // 聚合落盘骨架（findBucket → 搬桶 → cap/溢出 → 满闸 → 写盘 → 诊断）在基类 persistAggregated。
            return $this->persistAggregated(
                $hash,
                fn (string $now)                                 => $this->build($hash, $e, $request, $now, $source, $meta, $lean),
                fn (array $existing, string $now, int $overflow) => $this->refresh($existing, $e, $request, $now, $source, $meta, $lean, $overflow),
                fn ()                                            => $this->logWriteFailure($e, $request),
            );
        } catch (Throwable $self) {
            // safeLog：日志写入本身也可能抛（database/slack 通道后端不可用），否则会逃出 record()
            // → 经 ExceptionDispatcher 冒泡进宿主异常链。record() 对调用方只返回 string|null，永不抛。
            $this->safeLog('warning', 'runtime-recorder failed: ' . $self->getMessage());

            return null;
        }
    }

    /**
     * 仅在 record() **真正写盘失败**（目录建不出 / 不可写）时记一次诊断。
     * disabled / 被过滤（4xx·dontReport）/ 桶满这些预期跳过虽也返回 null，但没动盘，不该报错。
     * 一次请求只记一次，避免刷屏。
     */
    private function logWriteFailure(Throwable $origin, ?Request $request): void
    {
        static $logged = false;
        if ($logged) {
            return;
        }
        $logged = true;

        $openDir = $this->basePath . '/open';
        // url 必须走 maskUrl：整条采集链路落盘 request.url 都脱敏，唯独这条诊断日志若用裸 fullUrl(),
        // 会把 ?token=/api_key= 等密钥明文写进宿主 laravel.log（常被 ELK/Loki 集中采集）。
        $this->safeLog('error', 'runtime-recorder: 写盘失败（目录不可写？） ' . $openDir, [
            'is_dir'   => is_dir($openDir),
            'writable' => is_writable(is_dir($openDir) ? $openDir : dirname($openDir)),
            'perms'    => is_dir($openDir) ? substr(sprintf('%o', (int) @fileperms($openDir)), -4) : null,
            'owner'    => is_dir($openDir) ? @fileowner($openDir) : null,
            'php_uid'  => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'origin'   => get_class($origin),
            'url'      => $request !== null ? $this->masker->maskUrl($request->fullUrl()) : null,
        ]);
    }

    /**
     * 构造一条「自检」runtime 记录（供 moo:cloud:test 验证推送管道），不落本地盘。
     *
     * 复用真实 build() 路径，保证形状与正常采集、与云端 intake 契约完全一致。hash 由固定的
     * SelfTestException 类 + 本方法所在 file:line + 固定 message 决定 → 稳定，重复自检只 upsert 同一条。
     *
     * @return array<string,mixed>
     */
    public function buildSelfTestRecord(): array
    {
        $e    = new SelfTestException('moo-monitor 连通性自检 —— moo:cloud:test 生成的测试记录，可安全忽略或解决');
        $now  = $this->nowIso();
        $hash = $this->makeHash($e);
        $data = $this->build($hash, $e, null, $now, 'self_test');
        // writeFile 平时才补 meta.updated_at；这里直接推送、不落盘，故手动补上（云端按它做增量/展示）。
        $data['meta']['updated_at'] = $now;

        return $data;
    }

    /**
     * 同一异常对象已被记录后，后续入口若带来更有业务价值的来源（如 queue_failed），只升级
     * meta.source / meta.sources，不增加 count。用于 ExceptionDispatcher 的 WeakMap 去重分支。
     *
     * @param array<string,mixed> $meta
     */
    public function tagSource(Throwable $e, string $source, array $meta = []): bool
    {
        if (! ($this->config['enabled'] ?? true)) {
            return false;
        }

        try {
            $hash   = $this->makeHash($e);
            $bucket = $this->findBucket($hash);
            if ($bucket === null) {
                return false;
            }

            $existing = $this->readFile($hash, $bucket);
            if (! is_array($existing)) {
                return false;
            }

            $oldMeta = is_array($existing['meta'] ?? null) ? $existing['meta'] : [];
            $newMeta = $this->runtimeMeta($source, $meta, $oldMeta);

            // 闸①（P2-2）：来源集合 + meta 字段无实质变化 → 不写盘，避免反复刷 mtime/updated_at（ping-pong 重推）。
            if ($this->metaUnchanged($oldMeta, $newMeta)) {
                return true;
            }
            // 闸②（P2-2）：当天已达 cap（yaml 已冻结）→ 不写盘，meta 变化随次日回填一起落，与 record 冻结口径一致。
            if ($this->dailyCapReached($existing, $this->nowIso())) {
                return true;
            }

            $existing['meta'] = $newMeta;

            return $this->writeFile($hash, $bucket, $existing);
        } catch (Throwable $self) {
            $this->safeLog('warning', 'runtime-recorder tag source failed: ' . $self->getMessage());

            return false;
        }
    }

    // ====================================================================
    // 内部：dontReport 过滤
    // ====================================================================

    private function shouldReport(Throwable $e): bool
    {
        // 3.1.0：类列表过滤（SKIP_CLASSES）全部下沉 host Laravel `$exceptions->dontReport([...])`。
        // 这里只保留行为判断：HttpException 4xx 不记、5xx 仍记。
        //
        // 注意口径（2026-07-09 修）：这条 5xx 分支只对 log_context / 手动 dispatch 等**旁路来源**生效。
        // reportable 主链根本走不到 HttpException —— 它在框架 internalDontReport 名单里、shouldntReport
        // 挡在 reportable 回调之前（vendor Handler.php），auto_hook 永远收不到 HttpException。真正把
        // abort(5xx) 采进来的是 MonitorProvider 的 renderable 观察者（source=http_5xx，矩阵 #5）。
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
        $msg = $this->masker->maskSecrets($this->masker->maskSensitiveSql($msg));
        $msg = preg_replace("/'[^']*'/", "'X'", $msg) ?? $msg;
        $msg = preg_replace('/"[^"]*"/', '"X"', $msg) ?? $msg;
        // UUID / 0x 内存地址 / 长 hex(token、hash 片段)→ 占位 —— 否则同一异常因 message 里可变的
        // UUID/地址（ModelNotFound 带 UUID 等）裂成多 hash。须在数字归一之前（否则 UUID 的数字先被吃掉）。
        $msg = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', 'U', $msg) ?? $msg;
        $msg = preg_replace('/\b0x[0-9a-f]+\b/i', '0xH', $msg)                                            ?? $msg;
        $msg = preg_replace('/\b[0-9a-f]{16,}\b/i', 'H', $msg)                                            ?? $msg;
        $msg = preg_replace('/\d+/', 'N', $msg)                                                           ?? $msg;

        // hash 前截断放宽到 1024（原 256 易把「前缀相同、尾部不同」的两条不同异常误并成一个 hash）。
        return substr($msg, 0, 1024);
    }

    // ====================================================================
    // 内部：构建 / 刷新
    // ====================================================================

    /**
     * @param array<string,mixed> $meta
     */
    private function build(string $hash, Throwable $e, ?Request $request, string $now, string $source = 'reportable', array $meta = [], bool $lean = false): array
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
            'exception'      => $this->extractException($e, $lean),
            'request'        => $lean ? $this->leanRequest($request) : $this->extractRequest($request, true),
            'context'        => $this->extractContext(),
            'trace'          => $lean ? $this->leanTrace($e) : $this->extractTrace($e),
            'source_snippet' => $lean ? $this->leanSnippet($e) : $this->extractSourceSnippet($e->getFile(), $e->getLine()),
            'payload'        => $lean ? [] : $this->extractPayload($request),
            'meta'           => $this->runtimeMeta($source, $lean ? $meta + ['lean' => true] : $meta),
        ];
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function refresh(array $existing, Throwable $e, ?Request $request, string $now, string $source = 'reportable', array $meta = [], bool $lean = false, int $overflow = 0): array
    {
        $existing['last_seen'] = $now;
        // count += 1 本次 + overflow 前日冻结期累积的真实发生次数（P2-1，overflow 通常为 0）
        $existing['count'] = (int) ($existing['count'] ?? 0) + 1 + max(0, $overflow);
        $existing['daily'] = $this->bumpDaily($existing['daily'] ?? null, $now);
        // 覆盖末次 request / payload / trace（保留 first_seen 不变）；lean 语境下同样降级采集。
        $existing['exception']      = $this->extractException($e, $lean);
        $existing['request']        = $lean ? $this->leanRequest($request) : $this->extractRequest($request, true);
        $existing['context']        = $this->extractContext();
        $existing['trace']          = $lean ? $this->leanTrace($e) : $this->extractTrace($e);
        $existing['source_snippet'] = $lean ? $this->leanSnippet($e) : $this->extractSourceSnippet($e->getFile(), $e->getLine());
        $existing['payload']        = $lean ? [] : $this->extractPayload($request);
        $existing['meta']           = $this->runtimeMeta($source, $lean ? $meta + ['lean' => true] : $meta, is_array($existing['meta'] ?? null) ? $existing['meta'] : []);

        return $existing;
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,mixed> $existing
     *
     * @return array<string,mixed>
     */
    /**
     * source 优先级真源（P2-2 收口）：值大者更「具体/高价值」。同一异常多入口时 meta.source 只升不降。
     * ExceptionDispatcher::sourcePriority 复用本表（sourceRank），不再各持一份、避免 ping-pong。
     */
    private const SOURCE_PRIORITY = [
        'queue_failed'  => 30,
        'schedule_exit' => 28,
        'http_5xx'      => 25,
        'log_context'   => 20,
        'log_message'   => 15,
        'reportable'    => 10,
    ];

    /** source → 优先级；未知来源（self_test 等）为 0。ExceptionDispatcher 复用同一真源。 */
    public static function sourceRank(string $source): int
    {
        return self::SOURCE_PRIORITY[$source] ?? 0;
    }

    /** 取更高优先级的 source（只升不降）：incoming 不低于 current 就用 incoming，否则保留 current。 */
    private function preferSource(string $incoming, ?string $current): string
    {
        if ($current === null || $current === '') {
            return $incoming;
        }

        return self::sourceRank($incoming) >= self::sourceRank($current) ? $incoming : $current;
    }

    private function runtimeMeta(string $source, array $meta = [], array $existing = []): array
    {
        $source          = $this->normalizeSource($source);
        $existingPrimary = isset($existing['source']) ? (string) $existing['source'] : null;
        $sources         = array_values(array_unique(array_filter(array_merge(
            (array) ($existing['sources'] ?? []),
            $existingPrimary !== null ? [$existingPrimary] : [],
            [$source],
        ))));
        $clean = [];
        foreach ($meta as $key => $value) {
            if (! is_string($key) || $key === 'source') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? mb_substr($value, 0, 500) : $value;
            }
        }

        return array_filter(array_merge($existing, $clean, [
            // source 只升不降（P2-2）：refresh 带来的低优先级来源不得把 meta.source 降回去；
            // sources 仍收「见过的全部来源」并集。
            'source'  => $this->preferSource($source, $existingPrimary),
            'sources' => $sources,
        ]), fn ($value) => $value !== null && $value !== '');
    }

    /** meta 是否无实质变化（忽略 writeFile 才补的 updated_at）——tagSource 据此免掉多余写盘。 */
    private function metaUnchanged(array $old, array $new): bool
    {
        unset($old['updated_at'], $new['updated_at']);

        return $old == $new;
    }

    private function normalizeSource(string $source): string
    {
        $source = preg_replace('/[^a-z0-9_\-]/i', '_', $source) ?: 'reportable';
        $source = trim(strtolower($source), '_-');

        return mb_substr($source !== '' ? $source : 'reportable', 0, 64);
    }

    private function extractException(Throwable $e, bool $lean = false): array
    {
        // lean 语境（fatal/OOM）：先截 512 再脱敏 —— 脱敏不降级，只是把正则跑在短串上省内存。
        $message = $lean ? mb_substr($e->getMessage(), 0, 512) : $e->getMessage();

        $out = [
            'class' => get_class($e),
            'code'  => (string) $e->getCode(),
            // QueryException 的 message 嵌着带 binding 值的 SQL(WHERE token='…')→ maskSensitiveSql;
            // 再叠 maskSecrets 兜住应用写进 message 的 JWT / Bearer / 裸密钥（非 SQL 形态）。
            'message' => $this->masker->maskSecrets($this->masker->maskSensitiveSql($message)),
            'file'    => $this->relPath($e->getFile()),
            'line'    => $e->getLine(),
        ];

        // 异常链 previous（失真 E）：包装异常（QueryException 里的 PDOException 等）丢根因 —— 采最多 3 层。
        // hash 仍按最外层算（聚合稳定性不变），云端契约只增可选字段。lean 语境省略（减内存分配）。
        if (! $lean) {
            $previous = $this->extractPrevious($e);
            if ($previous !== []) {
                $out['previous'] = $previous;
            }
        }

        return $out;
    }

    /**
     * 异常链（getPrevious）最多 3 层，每层同款双层脱敏；第 4 层及以下截断。
     *
     * @return array<int,array{class:string,message:string,file:string,line:int}>
     */
    private function extractPrevious(Throwable $e): array
    {
        $chain = [];
        $prev  = $e->getPrevious();
        while ($prev !== null && count($chain) < 3) {
            $chain[] = [
                'class'   => get_class($prev),
                'message' => $this->masker->maskSecrets($this->masker->maskSensitiveSql($prev->getMessage())),
                'file'    => $this->relPath($prev->getFile()),
                'line'    => $prev->getLine(),
            ];
            $prev = $prev->getPrevious();
        }

        return $chain;
    }

    /**
     * 危险语境探测（矩阵 #7）：命中则 record() 走 lean path。
     * ① Laravel shutdown 兜底把 fatal/OOM 包成 FatalError（vendor HandleExceptions.php:251）；
     * ② 已用内存逼近 memory_limit（>0.9）—— 再跑重采集路径极易二次耗尽。memory_limit=-1 视为无限、不触发。
     */
    private function isDangerousContext(Throwable $e): bool
    {
        if ($e instanceof \Symfony\Component\ErrorHandler\Error\FatalError) {
            return true;
        }
        $limit = $this->memoryLimitBytes();

        return $limit > 0 && memory_get_usage(true) / $limit > 0.9;
    }

    /** 解析 ini memory_limit 为字节；-1 / 空 / 非法 → 0（视为无限制，不触发内存比率判定）。 */
    private function memoryLimitBytes(): int
    {
        $raw = strtolower(trim((string) ini_get('memory_limit')));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $num  = (int) $raw;
        $unit = substr($raw, -1);

        return match ($unit) {
            'g'     => $num * 1024 * 1024 * 1024,
            'm'     => $num * 1024 * 1024,
            'k'     => $num * 1024,
            default => $num,
        };
    }

    /** lean request：只留 method / url（url 仍走 maskUrl，脱敏不降级），丢 ip/user/guard 采集。 */
    private function leanRequest(?Request $request): array
    {
        if ($request === null) {
            return ['method' => 'CLI', 'url' => null];
        }

        return [
            'method' => $request->getMethod(),
            'url'    => $this->masker->maskUrl($request->fullUrl()),
        ];
    }

    /** lean trace：getTraceAsString 直接截 4KB，只做 maskSecrets 单遍，不叠 SQL 脱敏、不扫 app_frames。 */
    private function leanTrace(Throwable $e): array
    {
        return [
            'full'       => $this->masker->maskSecrets(substr($e->getTraceAsString(), 0, 4096)),
            'app_frames' => [],
        ];
    }

    /** lean snippet：跳过 file() 整读，只留定位信息。 */
    private function leanSnippet(Throwable $e): array
    {
        return ['file' => $this->relPath($e->getFile()), 'line' => $e->getLine(), 'language' => 'php', 'code' => ''];
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
        // 先脱敏再截断：trace 里的函数参数可能含 JWT / 裸密钥；先 mask 避免被截断切断导致漏 mask。
        // maskSensitiveSql + maskSecrets 双层，与 extractException 的 message 脱敏强度对齐（原先只做后者）。
        $full     = $this->masker->maskSecrets($this->masker->maskSensitiveSql($e->getTraceAsString()));
        $maxBytes = (int) ($this->config['trace_max_bytes'] ?? 65536);
        if (strlen($full) > $maxBytes) {
            $full = substr($full, 0, $maxBytes) . "\n... (truncated)";
        }

        // 应用帧前缀可配置（失真 F）：默认只认 app/ + routes/，Modules/、src/、database/ 布局的宿主
        // 调用栈会全空。宿主按自己的目录布局配 runtime.app_frame_prefixes 即可。
        $prefixes = array_values(array_filter(array_map('strval', (array) ($this->config['app_frame_prefixes'] ?? ['app/', 'routes/']))));

        $appFrames = [];
        foreach ($e->getTrace() as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }
            $rel     = $this->relPath($frame['file']);
            $matched = false;
            foreach ($prefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($rel, $prefix)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
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
                // 总节点预算：深度有上限，但「扁平超大数组」（几万元素的列表/宽 map）会逐个 mask + truncate,
                // 单条异常 payload 撑爆写盘耗时与 yaml 体积。耗尽预算即截断。
                if (--$budget < 0) {
                    $out['__truncated__'] = '<too many keys>';
                    break;
                }
                $kStr = (string) $k;
                if ($this->masker->shouldMaskKey($kStr)) {
                    $out[$kStr] = '***';

                    continue;
                }
                $out[$kStr] = $this->maskRecursive($v, $depth + 1, $budget);
            }

            return $out;
        }
        if (is_string($value)) {
            $value = $this->masker->maskSecrets($value);
            $cap   = (int) ($this->config['string_truncate'] ?? 200);
            // 多字节安全 + 非负长度：原先用字节级 substr，中文/emoji 截在 UTF-8 字符中间会产出非法串，
            // 被 symfony/yaml 整段 !!binary base64 化（不可读）；cap<20 时 cap-20 为负还会反向放大。
            // 改用字符级 mb_substr + max(0，…)，与 SqlSlowRecorder::truncate 对齐。
            $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
            if ($len > $cap) {
                $keep  = max(0, $cap - 20);
                $slice = function_exists('mb_substr') ? mb_substr($value, 0, $keep, 'UTF-8') : substr($value, 0, $keep);

                return $slice . '…<+' . ($len - $keep) . ' chars>';
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
     * 单条 yaml 派生 list/count/prune 用字段（原 index 持有的字段全在这）。
     * 桶名为 status 唯一真源 — 防 yaml 内 `status` 字段跟落盘位置不同步。
     */
    protected function deriveRow(string $bucket, array $data): array
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
