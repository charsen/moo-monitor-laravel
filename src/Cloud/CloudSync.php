<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Cloud;

use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 把本地 runtime / 慢 SQL 的 yaml 记录增量推送到 moo-scaffold-cloud。
 *
 * 设计取舍(严谨性):
 *   - 解耦请求链路:只读盘 + 发 HTTP,绝不挂在异常/查询回调里,云端故障不拖慢宿主 app。
 *   - 幂等:云端按 (project, hash) upsert,重复推送只是覆盖,无副作用。
 *   - 增量:按记录的 meta.updated_at 游标,只推「自上次成功推送后有变化」的;
 *     游标存本地 json(每端各自维护),丢了顶多触发一次全量重推(因幂等无害)。
 *   - 失败不前进游标:任一批失败即停,下次重试同一批,不会漏。
 *   - 只推 open + resolved 两桶(用户在意的活跃集);deleted 不上云(本地软删,云端各自生命周期)。
 *
 * 记录原样转发:本地 yaml 的嵌套结构(exception/request/context/trace、sql/took/at…)
 * 与云端 intake 的 map() 逐字段对齐,无需再做形状转换。
 */
class CloudSync
{
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

    public function __construct(?string $cursorFile = null)
    {
        $this->cursorFile = $cursorFile
            ?? (function_exists('storage_path') ? storage_path('moo-monitor/cloud-sync.json') : 'moo-monitor-cloud-sync.json');
    }

    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * 各类型的推送游标(= 最近已推记录的 meta.updated_at,作"已同步至"水位)。
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
     * @return array{type:string,skipped:bool,reason:?string,scanned:int,changed:int,pushed:int,batches:int,ok:bool,error:?string}
     */
    public function sync(string $type, bool $all = false, bool $dryRun = false): array
    {
        $base = self::TYPES[$type] ?? null;
        if ($base === null) {
            return $this->result($type, ok: false, error: "未知类型:{$type}");
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

        // 游标(= 上次已推水位,毫秒精度浮点 epoch)。先算,再用它给 readRecords 做 mtime 预筛。
        $cursor      = $all ? null : ($this->readState()[$type] ?? null);
        $cursorEpoch = $cursor ? $this->epochFloat($cursor) : 0.0;

        // 读取 open + resolved 两桶。是否增量推送由记录内 meta.updated_at / last_seen 与游标比较决定。
        $basePath = $this->resolvePath((string) config($base['path'], ''));
        $read     = $this->readRecords($basePath);
        $scanned  = $read['scanned'];
        $records  = $read['records'];

        $changed   = [];
        $maxEpoch  = $cursorEpoch;
        $maxCursor = $cursor;
        foreach ($records as $rec) {
            $ts    = (string) ($rec['meta']['updated_at'] ?? $rec['last_seen'] ?? '');
            $epoch = $ts !== '' ? $this->epochFloat($ts) : 0.0;
            if (! $all && $cursor !== null && $epoch !== 0.0 && $epoch <= $cursorEpoch) {
                continue;
            }
            $changed[] = $rec;
            if ($epoch > $maxEpoch) {
                $maxEpoch  = $epoch;
                $maxCursor = $ts;
            }
        }

        if ($dryRun) {
            return $this->result($type, scanned: $scanned, changed: count($changed), ok: true);
        }
        if ($changed === []) {
            return $this->result($type, scanned: $scanned, changed: 0, pushed: 0, batches: 0, ok: true);
        }

        // 分批推送;任一批失败即停,不前进游标
        $batchSize = max(1, (int) ($cfg['batch'] ?? 100));
        $pushed    = 0;
        $batches   = 0;
        foreach (array_chunk($changed, $batchSize) as $chunk) {
            $batches++;
            $r = $client->send($base['endpoint'], $chunk);
            if (! $r['ok']) {
                return $this->result($type, scanned: $scanned, changed: count($changed), pushed: $pushed, batches: $batches, ok: false, error: $r['error']);
            }
            $pushed += count($chunk);
        }

        if ($maxCursor !== null) {
            $this->writeState($type, $maxCursor);
        }

        return $this->result($type, scanned: $scanned, changed: count($changed), pushed: $pushed, batches: $batches, ok: true);
    }

    /**
     * 「本地降级为临时缓冲」的回收:推送成功后调用。
     *   - resolved 桶:已随推送进云端、由云端管生命周期 → 全清;
     *   - open 桶:留作聚合锚点,仅清 last_seen 超过 $retentionDays 天的(<=0 不清);
     *   - deleted 桶:不在推送范围(云端从未收到)→ 一律不动,避免静默丢未上云的数据。
     *
     * @return array{purged:int,prunedOpen:int}
     */
    public function pruneLocal(string $type, int $retentionDays): array
    {
        $base = self::TYPES[$type] ?? null;
        if ($base === null) {
            return ['purged' => 0, 'prunedOpen' => 0];
        }
        // N<=0:完全不回收(本地与云端并存),保持现状直接返回 —— 一个字节都不动。
        if ($retentionDays <= 0) {
            return ['purged' => 0, 'prunedOpen' => 0];
        }
        $basePath = $this->resolvePath((string) config($base['path'], ''));

        // resolved 桶:只清「已随推送上云」的 —— meta.updated_at(回退 resolved_at/last_seen)<= 推送游标。
        // push 读取后、prune 前才被 resolve 的记录尚未上云 → 留着,下轮推完再清。无游标(从未成功推过)→ 不删。
        // 不用 mtime 免解析快删:迁移/复制/外部同步可能保留旧 mtime,但 yaml 内 updated_at 更晚。
        $cursorEpoch = $this->epochFloat((string) ($this->readState()[$type] ?? ''));
        $purged      = 0;
        foreach (glob($basePath . '/resolved/*.yaml') ?: [] as $file) {
            if ($cursorEpoch <= 0) {
                continue; // 没有已推水位 → 一律不删 resolved(避免删未上云的)
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
                continue; // 尚未上云 → 留着,下轮再清
            }
            if (@unlink($file)) {
                $purged++;
            }
        }

        // open 桶:留作聚合锚点,仅清 last_seen 超过 N 天的 dormant 记录。
        $prunedOpen = 0;
        $cutoff     = time() - $retentionDays * 86400;
        foreach (glob($basePath . '/open/*.yaml') ?: [] as $file) {
            try {
                $rec = Yaml::parse((string) @file_get_contents($file));
            } catch (Throwable) {
                continue;
            }
            if (! is_array($rec)) {
                continue;
            }
            $ts    = (string) ($rec['last_seen'] ?? $rec['meta']['updated_at'] ?? '');
            $epoch = $ts !== '' ? (int) strtotime($ts) : 0;
            if ($epoch !== 0 && $epoch < $cutoff && @unlink($file)) {
                $prunedOpen++;
            }
        }

        return ['purged' => $purged, 'prunedOpen' => $prunedOpen];
    }

    /**
     * 把 base_path 下 open + resolved 两桶的 yaml 解析成记录(原样,补 hash)。
     *
     * 不用 mtime 预筛:迁移、复制、外部同步可能保留旧 mtime,但 yaml 内 meta.updated_at 更晚。
     * 这里宁可多 parse 几百条本地缓冲,也不能漏推记录。
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
                    continue; // 坏文件跳过,不阻断整体推送
                }
                if (! is_array($rec)) {
                    continue;
                }
                $rec['hash'] = $hash;
                // 无 updated_at / last_seen 的记录(legacy / 手改 yaml)用文件 mtime 兜底一个时间戳。
                // 否则主循环 epoch=0 → 每次都进 changed 且游标永不前进 → 该记录被永久重推(2026-06-09 修)。
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

    private function resolvePath(string $relative): string
    {
        if ($relative === '') {
            return $relative;
        }

        return \Mooeen\Monitor\Recorder\RuntimeErrorRecorder::resolveStoragePath($relative);
    }

    /** ISO-8601(可含毫秒)→ 浮点 epoch 秒;解析失败返 0.0。strtotime 会丢毫秒,故走 DateTimeImmutable。 */
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
        // 与 recorder 同策略:纯 `*` 的 .gitignore 连自身一起屏蔽,目录在宿主 git status 里零噪音。
        $gitignore = $dir . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }

        // flock 串行化整个 read-modify-write:并发的两个 push(不同 --type / 重叠调度)否则会
        // 各自读到旧 state、互相覆盖对方刚写的另一 type 游标 → 那个 type 下次全量重推。原子写只防
        // 文件截断、不防这种 lost-update(2026-06-09 修)。
        $lock = @fopen($this->cursorFile . '.lock', 'c');
        if ($lock !== false) {
            @flock($lock, LOCK_EX);
        }
        try {
            $state        = $this->readState();
            $state[$type] = $cursor;

            // 原子写(同 yaml 路径):崩溃/磁盘满 mid-write 不会把 cursor json 截成坏文件 → 否则 readState
            // 退回空、触发一次全量重推 + resolved 桶因游标=0 暂停回收(buffer bloat)。
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

    /** @return array{type:string,skipped:bool,reason:?string,scanned:int,changed:int,pushed:int,batches:int,ok:bool,error:?string} */
    private function result(
        string $type,
        bool $skipped = false,
        ?string $reason = null,
        int $scanned = 0,
        int $changed = 0,
        int $pushed = 0,
        int $batches = 0,
        bool $ok = true,
        ?string $error = null,
    ): array {
        return compact('type', 'skipped', 'reason', 'scanned', 'changed', 'pushed', 'batches', 'ok', 'error');
    }
}
