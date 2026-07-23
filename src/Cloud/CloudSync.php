<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Cloud;

use Mooeen\Monitor\Concerns\SafelyLogs;
use Mooeen\Monitor\StorageScope;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 把本地 runtime / 慢 SQL 的 yaml 记录增量推送到 moo-scaffold-cloud。
 *
 * 设计取舍（严谨性）：
 *   - 解耦请求链路：只读盘 + 发 HTTP，绝不挂在异常/查询回调里，云端故障不拖慢宿主 app。
 *   - 幂等：云端按 (project, hash) upsert，重复推送只是覆盖，无副作用。
 *   - 增量：全局游标 + 部分批次的 hash/version 确认水位；一条失败时已完成项不重发，只重试失败项。
 *   - 永久拒收移入 cloud-rejected；retryable 拒收保留原文件，且不越过失败项推进全局游标。
 *   - 只推 open + resolved 两桶（用户在意的活跃集）；deleted 不上云（本地软删，云端各自生命周期）。
 *
 * 记录原样转发：本地 yaml 的嵌套结构（exception/request/context/trace、sql/took/at…）
 * 与云端 intake 的 map() 逐字段对齐，无需再做形状转换。
 */
class CloudSync
{
    use SafelyLogs;

    /** @var array<string,array{path:string,endpoint:string,push_key:string}> */
    private const TYPES = [
        'runtimes' => [
            'path'     => 'moo-monitor.runtime.path',
            'endpoint' => CloudClient::PATH_RUNTIMES,
            'push_key' => 'runtimes',
        ],
        'slow_sql' => [
            'path'     => 'moo-monitor.sql_slow.path',
            'endpoint' => CloudClient::PATH_SLOW_QUERIES,
            'push_key' => 'slow_sql',
        ],
    ];

    private string $cursorFile;

    private string $ackFile;

    public function __construct(?string $cursorFile = null)
    {
        $defaultCursor    = function_exists('storage_path') ? storage_path('moo-monitor/cloud-sync.json') : 'moo-monitor-cloud-sync.json';
        $this->cursorFile = $cursorFile ?? StorageScope::scopeFile($defaultCursor);
        $this->ackFile    = $this->cursorFile . '.acks';
    }

    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * 各类型的推送游标（= 最近已推记录的 meta.updated_at，作「已同步至」水位）。
     * 未推过的类型不在返回里。供 Cloud 控制台展示「上次推送」用。
     *
     * @return array<string,string>
     */
    public function cursors(): array
    {
        return $this->readState();
    }

    /**
     * 同步一类记录。
     *
     * @return array{type:string,skipped:bool,reason:?string,scanned:int,changed:int,pushed:int,rejected:int,batches:int,ok:bool,error:?string,failed_hashes:array<int,string>,rejected_hashes:array<int,string>}
     */
    public function sync(string $type, bool $all = false, bool $dryRun = false): array
    {
        $base = self::TYPES[$type] ?? null;
        if ($base === null) {
            return $this->result($type, ok: false, error: "未知类型：{$type}");
        }

        $cfg = (array) config('moo-monitor.cloud', []);

        if (! ($cfg['enabled'] ?? false)) {
            return $this->result($type, skipped: true, reason: 'cloud 未启用 (MOO_MONITOR_CLOUD_ENABLED=false)');
        }
        if (! (($cfg['push'][$base['push_key']] ?? true))) {
            return $this->result($type, skipped: true, reason: "{$type} 推送已关闭");
        }

        $client = new CloudClient($cfg);
        if (! $client->configured()) {
            return $this->result($type, ok: false, error: 'cloud base_url / token 未配置');
        }

        // Scheduler 的 withoutOverlapping 只覆盖自动调度入口；手动 CLI、Scaffold 页面或多个
        // 调度器仍可能同时进入同一类型。sync 的 cursor/ack 是「读到内存 → HTTP → 整份写回」，
        // 只锁 writeState/writeAckState 会让两个 partial 结果互相覆盖已确认水位、造成重复上报。
        // 因此按 cursorFile + type 锁住完整同步；runtimes / slow_sql 仍可彼此并行。
        $lockDir = dirname($this->cursorFile);
        if (! is_dir($lockDir)) {
            @mkdir($lockDir, 0755, true);
        }
        if (! is_dir($lockDir)) {
            return $this->result($type, ok: false, error: '无法创建 cloud 同步状态目录');
        }
        $gitignore = $lockDir . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
        $lock = @fopen($this->cursorFile . '.' . $type . '.sync.lock', 'c');
        if ($lock === false) {
            return $this->result($type, ok: false, error: '无法创建 cloud 同步锁');
        }
        $wouldBlock = 0;
        if (! @flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
            @fclose($lock);

            return $wouldBlock === 1
                ? $this->result($type, skipped: true, reason: "{$type} 同类型推送正在执行")
                : $this->result($type, ok: false, error: '无法获取 cloud 同步锁');
        }

        try {
            // 全局游标覆盖连续成功水位；acks 记录「失败洞之后已经成功」的 hash/version，避免它们随失败项重复上报。
            $cursor      = $all ? null : ($this->readState()[$type] ?? null);
            $cursorEpoch = $cursor ? $this->epochFloat($cursor) : 0.0;
            $acks        = $all ? [] : (($this->readAckState()[$type] ?? []));
            $acks        = is_array($acks) ? $acks : [];
            $hadAcks     = $acks !== [];

            // 读取 open + resolved 两桶。是否增量推送由记录内 meta.updated_at / last_seen 与游标比较决定。
            $basePath = $this->resolvePath((string) config($base['path'], ''));
            $read     = $this->readRecords($basePath);
            $scanned  = $read['scanned'];
            $records  = $read['records'];

            $changed    = [];
            $liveHashes = [];
            $maxEpoch   = $cursorEpoch;
            $maxCursor  = $cursor;
            foreach ($records as $rec) {
                $hash  = (string) ($rec['hash'] ?? '');
                $ts    = $this->recordTimestamp($rec);
                $epoch = $ts !== '' ? $this->epochFloat($ts) : 0.0;
                if ($hash !== '') {
                    $liveHashes[$hash] = true;
                }
                if ($epoch > $maxEpoch) {
                    $maxEpoch  = $epoch;
                    $maxCursor = $ts;
                }
                if (! $all && $cursor !== null && $epoch !== 0.0 && $epoch <= $cursorEpoch) {
                    continue;
                }
                if (! $all && $hash !== '' && $this->isAcknowledged($ts, $acks[$hash] ?? null)) {
                    continue;
                }
                $changed[] = $rec;
            }
            $acks = array_intersect_key($acks, $liveHashes);

            if ($dryRun) {
                return $this->result($type, scanned: $scanned, changed: count($changed), ok: true);
            }
            if ($changed === []) {
                if ($maxCursor !== null && $maxEpoch > $cursorEpoch) {
                    $this->writeState($type, $maxCursor);
                    $this->writeAckState($type, []);
                } elseif ($hadAcks) {
                    $this->writeAckState($type, $acks);
                }

                return $this->result($type, scanned: $scanned, changed: 0, pushed: 0, batches: 0, ok: true);
            }

            // 分批推送。逐条回执中的成功项立即确认；retryable 项留下形成「洞」，但不拖累同批其余记录。
            $batchSize      = max(1, (int) ($cfg['batch'] ?? 100));
            $pushed         = 0;
            $rejected       = 0;
            $batches        = 0;
            $failedHashes   = [];
            $rejectedHashes = [];
            foreach (array_chunk($changed, $batchSize) as $chunk) {
                $batches++;
                $r = $client->send($base['endpoint'], $chunk);
                if (! $r['ok']) {
                    $failedHashes = array_merge($failedHashes, $this->hashesOf($chunk));
                    $this->writeAckState($type, $acks);
                    $this->safeLog('warning', "moo-monitor: 推送 {$type} 失败，游标不前进、本地缓冲将累积。云端错误：{$r['error']}", [
                        'type'          => $type,
                        'failed_hashes' => $failedHashes,
                        'batch'         => $batches,
                    ]);

                    return $this->result($type, scanned: $scanned, changed: count($changed), pushed: $pushed, rejected: $rejected, batches: $batches, ok: false, error: $r['error'], failedHashes: $failedHashes, rejectedHashes: $rejectedHashes);
                }

                if ($r['results'] === []) {
                    // 兼容旧 Cloud 的完整成功响应（skipped=0）：整批均已确认。
                    foreach ($chunk as $rec) {
                        $this->acknowledge($acks, $rec);
                        $this->deleteResolvedIfUnchanged($basePath, $rec);
                        $pushed++;
                    }
                    $this->writeAckState($type, $acks);

                    continue;
                }

                foreach ($r['results'] as $item) {
                    $rec  = $chunk[$item['index']];
                    $hash = (string) $rec['hash'];
                    if (in_array($item['status'], ['saved', 'filtered'], true)) {
                        $this->acknowledge($acks, $rec);
                        $this->deleteResolvedIfUnchanged($basePath, $rec);
                        $pushed++;

                        continue;
                    }
                    if ($item['retryable']) {
                        $failedHashes[] = $hash;

                        continue;
                    }
                    if ($this->quarantineIfUnchanged($basePath, $rec, (string) $item['reason'])) {
                        $rejected++;
                        $rejectedHashes[] = $hash;
                    } else {
                        // 文件在请求期间又更新或隔离失败：不能丢新版本，保留到下轮重试。
                        $failedHashes[] = $hash;
                    }
                }
                $this->writeAckState($type, $acks);
            }

            $failedHashes = array_values(array_unique($failedHashes));
            if ($failedHashes !== []) {
                $error = count($failedHashes) . ' 条记录等待重试；其余记录已确认，不会重复上报';
                $this->safeLog('warning', "moo-monitor: 推送 {$type} 部分完成。{$error}", [
                    'type'            => $type,
                    'failed_hashes'   => $failedHashes,
                    'rejected_hashes' => $rejectedHashes,
                    'batches'         => $batches,
                ]);

                return $this->result($type, scanned: $scanned, changed: count($changed), pushed: $pushed, rejected: $rejected, batches: $batches, ok: false, error: $error, failedHashes: $failedHashes, rejectedHashes: $rejectedHashes);
            }

            if ($maxCursor !== null) {
                $this->writeState($type, $maxCursor);
            }
            $this->writeAckState($type, []);

            return $this->result($type, scanned: $scanned, changed: count($changed), pushed: $pushed, rejected: $rejected, batches: $batches, ok: true, rejectedHashes: $rejectedHashes);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /**
     * 「本地降级为临时缓冲」的回收：推送成功后调用。
     *   - resolved 桶：已随推送进云端、由云端管生命周期 → 全清；
     *   - open 桶：累计 count 的唯一锚点，不能删除；否则同 hash 重建从 1 开始，Cloud 的 max 合并会让
     *     count / daily_counts / RECUR 通知长期冻结；
     *   - deleted 桶：不在推送范围（云端从未收到）→ 一律不动，避免静默丢未上云的数据。
     *
     * @return array{purged:int,prunedOpen:int}
     */
    public function pruneLocal(string $type, int $retentionDays): array
    {
        $base = self::TYPES[$type] ?? null;
        if ($base === null) {
            return ['purged' => 0, 'prunedOpen' => 0];
        }
        // N<=0：完全不回收（本地与云端并存），保持现状直接返回 —— 一个字节都不动。
        if ($retentionDays <= 0) {
            return ['purged' => 0, 'prunedOpen' => 0];
        }
        $basePath = $this->resolvePath((string) config($base['path'], ''));

        // resolved 桶：只清「已随推送上云」的 —— meta.updated_at（回退 resolved_at/last_seen）<= 推送游标。
        // push 读取后、prune 前才被 resolve 的记录尚未上云 → 留着，下轮推完再清。无游标（从未成功推过）→ 不删。
        // 不用 mtime 免解析快删：迁移/复制/外部同步可能保留旧 mtime，但 yaml 内 updated_at 更晚。
        $cursorEpoch = $this->epochFloat((string) ($this->readState()[$type] ?? ''));
        $purged      = 0;
        foreach (glob($basePath . '/resolved/*.yaml') ?: [] as $file) {
            if ($cursorEpoch <= 0) {
                continue; // 没有已推水位 → 一律不删 resolved（避免删未上云的）
            }
            try {
                $rec = Yaml::parse((string) @file_get_contents($file));
            } catch (Throwable) {
                continue; // 解析失败 → 保守不删
            }
            if (! is_array($rec)) {
                continue;
            }
            $ts    = (string) ($rec['meta']['updated_at'] ?? $rec['resolved_at'] ?? $rec['last_seen'] ?? '');
            $epoch = $ts !== '' ? $this->epochFloat($ts) : (float) (@filemtime($file) ?: PHP_FLOAT_MAX);
            if ($epoch > $cursorEpoch) {
                continue; // 尚未上云 → 留着，下轮再清
            }
            // 外部迁移/工具可能在 parse 后覆盖同名 resolved；删除前按本次快照再核一次，
            // 与逐条确认后的 resolved 回收保持同一保守口径。
            $snapshot = $this->recordTimestamp($rec);
            if ($snapshot !== '' && $this->fileMatchesSnapshot($file, $snapshot) && @unlink($file)) {
                $purged++;
            }
        }

        // 返回字段保留兼容旧调用方；open 现在明确永不按 retention 回收。
        return ['purged' => $purged, 'prunedOpen' => 0];
    }

    /**
     * 把 base_path 下 open + resolved 两桶的 yaml 解析成记录（原样，补 hash）。
     *
     * 不用 mtime 预筛：迁移、复制、外部同步可能保留旧 mtime，但 yaml 内 meta.updated_at 更晚。
     * 这里宁可多 parse 几百条本地缓冲，也不能漏推记录。
     *
     * @return array{scanned:int,records:array<int,array>}
     */
    private function readRecords(string $basePath): array
    {
        $scanned = 0;
        $out     = [];
        foreach (['open', 'resolved'] as $bucket) {
            foreach (glob($basePath . '/' . $bucket . '/*.yaml') ?: [] as $file) {
                $scanned++;
                $hash = basename($file, '.yaml');
                if (! preg_match('/^[a-f0-9]{12}$/', $hash)) {
                    continue;
                }
                try {
                    $rec = Yaml::parse((string) @file_get_contents($file));
                } catch (Throwable) {
                    continue; // 坏文件跳过，不阻断整体推送
                }
                if (! is_array($rec)) {
                    continue;
                }
                $rec['hash'] = $hash;
                // 无 updated_at / last_seen 的记录（legacy / 手改 yaml）用文件 mtime 兜底一个时间戳。
                // 否则主循环 epoch=0 → 每次都进 changed 且游标永不前进 → 该记录被永久重推（2026-06-09 修）。
                $hasTs = (($rec['meta']['updated_at'] ?? '') !== '') || (($rec['last_seen'] ?? '') !== '');
                if (! $hasTs) {
                    $mt = @filemtime($file);
                    if ($mt !== false) {
                        if (! isset($rec['meta']) || ! is_array($rec['meta'])) {
                            $rec['meta'] = [];
                        }
                        $rec['meta']['updated_at'] = date('Y-m-d\TH:i:s', $mt);
                    }
                }
                $out[] = $rec;
            }
        }

        return ['scanned' => $scanned, 'records' => $out];
    }

    private function recordTimestamp(array $rec): string
    {
        return (string) ($rec['meta']['updated_at'] ?? $rec['last_seen'] ?? '');
    }

    private function isAcknowledged(string $recordTimestamp, $ackTimestamp): bool
    {
        if (! is_string($ackTimestamp) || $recordTimestamp === '') {
            return false;
        }
        $recordEpoch = $this->epochFloat($recordTimestamp);
        $ackEpoch    = $this->epochFloat($ackTimestamp);

        return $recordEpoch > 0.0 && $ackEpoch > 0.0
            ? $recordEpoch <= $ackEpoch
            : $recordTimestamp === $ackTimestamp;
    }

    /** @param array<string,string> $acks */
    private function acknowledge(array &$acks, array $rec): void
    {
        $hash      = (string) ($rec['hash'] ?? '');
        $timestamp = $this->recordTimestamp($rec);
        if ($hash !== '' && $timestamp !== '') {
            $acks[$hash] = $timestamp;
        }
    }

    /** 已确认的 resolved 快照可单文件回收；若请求期间文件更新则保留新版本。 */
    private function deleteResolvedIfUnchanged(string $basePath, array $rec): bool
    {
        $hash = (string) ($rec['hash'] ?? '');
        $file = $basePath . '/resolved/' . $hash . '.yaml';
        if ($hash === '' || ! is_file($file) || ! $this->fileMatchesSnapshot($file, $this->recordTimestamp($rec))) {
            return false;
        }

        return @unlink($file);
    }

    /** 永久拒收不删除：移入 cloud-rejected 留证；文件已更新时保守留在原桶重试。 */
    private function quarantineIfUnchanged(string $basePath, array $rec, string $reason): bool
    {
        $hash = (string) ($rec['hash'] ?? '');
        if ($hash === '') {
            return false;
        }
        $source = null;
        foreach (['open', 'resolved'] as $bucket) {
            $candidate = $basePath . '/' . $bucket . '/' . $hash . '.yaml';
            if (is_file($candidate)) {
                $source = $candidate;

                break;
            }
        }
        if ($source === null || ! $this->fileMatchesSnapshot($source, $this->recordTimestamp($rec))) {
            return false;
        }

        $dir = $basePath . '/cloud-rejected';
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (! is_dir($dir)) {
            return false;
        }
        if (! is_file($dir . '/.gitignore')) {
            @file_put_contents($dir . '/.gitignore', "*\n");
        }
        $safeReason = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', $reason), '-');
        $unique     = substr(md5(uniqid('', true)), 0, 8);
        $target     = $dir . '/' . $hash . '-' . date('YmdHis') . '-' . $unique . '-' . ($safeReason !== '' ? $safeReason : 'rejected') . '.yaml';

        return @rename($source, $target);
    }

    private function fileMatchesSnapshot(string $file, string $snapshotTimestamp): bool
    {
        if ($snapshotTimestamp === '') {
            return false;
        }
        try {
            $rec = Yaml::parse((string) @file_get_contents($file));
        } catch (Throwable) {
            return false;
        }
        if (! is_array($rec)) {
            return false;
        }
        $currentTimestamp = $this->recordTimestamp($rec);
        if ($currentTimestamp === '') {
            $mtime            = @filemtime($file);
            $currentTimestamp = $mtime === false ? '' : date('Y-m-d\TH:i:s', $mtime);
        }

        return $this->isAcknowledged($currentTimestamp, $snapshotTimestamp)
            && $this->isAcknowledged($snapshotTimestamp, $currentTimestamp);
    }

    private function resolvePath(string $relative): string
    {
        if ($relative === '') {
            return $relative;
        }

        return \Mooeen\Monitor\Recorder\RuntimeErrorRecorder::resolveStoragePath(StorageScope::scopePath($relative));
    }

    /** ISO-8601（可含毫秒）→ 浮点 epoch 秒；解析失败返 0.0。strtotime 会丢毫秒，故走 DateTimeImmutable。 */
    private function epochFloat(string $iso): float
    {
        if ($iso === '') {
            return 0.0;
        }
        try {
            return (float) (new \DateTimeImmutable($iso))->format('U.u');
        } catch (Throwable) {
            return 0.0;
        }
    }

    /** @return array<string,string> */
    private function readState(): array
    {
        if (! is_file($this->cursorFile)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($this->cursorFile), true);

        return is_array($json) ? $json : [];
    }

    private function writeState(string $type, string $cursor): void
    {
        $dir = dirname($this->cursorFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // 与 recorder 同策略：纯 `*` 的 .gitignore 连自身一起屏蔽，目录在宿主 git status 里零噪音。
        $gitignore = $dir . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }

        // flock 串行化整个 read-modify-write：并发的两个 push（不同 --type / 重叠调度）否则会
        // 各自读到旧 state、互相覆盖对方刚写的另一 type 游标 → 那个 type 下次全量重推。原子写只防
        // 文件截断、不防这种 lost-update(2026-06-09 修)。
        $lock = @fopen($this->cursorFile . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            $state        = $this->readState();
            $state[$type] = $cursor;

            // 原子写（同 yaml 路径）：崩溃/磁盘满 mid-write 不会把 cursor json 截成坏文件 → 否则 readState
            // 退回空、触发一次全量重推 + resolved 桶因游标=0 暂停回收（buffer bloat）。
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tmp  = $this->cursorFile . '.tmp' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $json) !== false) {
                if (! @rename($tmp, $this->cursorFile)) {
                    @unlink($tmp);
                }
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /** @return array<string,array<string,string>> */
    private function readAckState(): array
    {
        if (! is_file($this->ackFile)) {
            return [];
        }
        $json = json_decode((string) @file_get_contents($this->ackFile), true);

        return is_array($json) ? $json : [];
    }

    /** @param array<string,string> $acks */
    private function writeAckState(string $type, array $acks): void
    {
        $dir = dirname($this->ackFile);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $gitignore = $dir . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }

        $lock = @fopen($this->ackFile . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            $state = $this->readAckState();
            if ($acks === []) {
                unset($state[$type]);
            } else {
                $state[$type] = $acks;
            }
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $tmp  = $this->ackFile . '.tmp' . bin2hex(random_bytes(4));
            if (@file_put_contents($tmp, $json) !== false) {
                if (! @rename($tmp, $this->ackFile)) {
                    @unlink($tmp);
                }
            }
        } finally {
            if ($lock !== false) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }
    }

    /**
     * 取一批记录的 hash 列表（失败批定位用）。
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array<int,string>
     */
    private function hashesOf(array $records): array
    {
        $out = [];
        foreach ($records as $rec) {
            $h = (string) ($rec['hash'] ?? '');
            if ($h !== '') {
                $out[] = $h;
            }
        }

        return $out;
    }

    /** @return array{type:string,skipped:bool,reason:?string,scanned:int,changed:int,pushed:int,rejected:int,batches:int,ok:bool,error:?string,failed_hashes:array<int,string>,rejected_hashes:array<int,string>} */
    private function result(
        string $type,
        bool $skipped = false,
        ?string $reason = null,
        int $scanned = 0,
        int $changed = 0,
        int $pushed = 0,
        int $rejected = 0,
        int $batches = 0,
        bool $ok = true,
        ?string $error = null,
        array $failedHashes = [],
        array $rejectedHashes = [],
    ): array {
        return compact('type', 'skipped', 'reason', 'scanned', 'changed', 'pushed', 'rejected', 'batches', 'ok', 'error')
            + ['failed_hashes' => $failedHashes, 'rejected_hashes' => $rejectedHashes];
    }
}
