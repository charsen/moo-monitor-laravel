<?php declare(strict_types=1);

namespace Mooeen\Monitor\Recorder;

use Mooeen\Monitor\Concerns\SafelyLogs;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * 三桶（open/resolved/deleted）YAML 记录器的抽象基类：管理 + 聚合 + 文件 IO + 每日写盘上限。
 *
 * RuntimeErrorRecorder / SqlSlowRecorder 是同骨架的两个 fork。原本靠三个互相引用的 trait
 * （ManagesBucketedRecords / WritesBucketedYaml / TracksDailyCap）拼装，读一个方法要跳 3~4 个文件、
 * 且靠 $this->config / $this->basePath / CACHE_OPEN_COUNT 常量 / 跨 trait 方法的隐式契约维系（明确反感的反模式）。
 * P4 收口成单一抽象基类：基类持有状态、声明抽象 deriveRow()，子类只留类型特有的 record/build/refresh/
 * makeHash/extract* + deriveRow。子类须提供 `public const CACHE_OPEN_COUNT`（open 数缓存 key，各类型独立）。
 *
 * 并发语义（刻意取舍，接入方须知）：record() 的 find→refresh(count+1)→writeFile 是无锁的
 * read-modify-write。多 worker / 队列在同一窗口命中同一 hash 时，后写者 last-write-wins 覆盖前写者，
 * 聚合 count 会偏低（热点条目偏差最大）。这是 best-effort 近似计数，不保证精确 —— 但不影响告警是否
 * 触发（count>=1 云端即可见），云端按 (project,hash) upsert 幂等，下次同 hash 写盘又基于最新落盘值续累，
 * 不会越漂越远。写盘本身是原子 rename，并发只会 last-write-wins，绝不写出半截/损坏 yaml。
 * 刻意不上 flock：会给宿主每条异常/慢查询的同步热路径加锁竞争，违背「采集绝不拖垮宿主」不变量。
 *
 * daily_cap 冻结期的计数真实性同样 best-effort（P2-1）：冻结期不写盘、但 cache increment 一个 overflow
 * 计数器，次日首次写盘时回填进 count；cache 后端不可用/被清则溢出计数丢失、退回「count 偏低」——
 * 与上文近似计数语义一致（P1-7② 决议：不引入本地文件计数器兜底）。
 */
abstract class BucketedYamlRecorder
{
    use SafelyLogs;

    protected string $basePath;

    protected array $config;

    /**
     * 单条 yaml → list/count/prune 用的派生 row（字段各类型不同）。桶名为 status 唯一真源。
     *
     * @param array<string,mixed> $data
     *
     * @return array<string,mixed>
     */
    abstract protected function deriveRow(string $bucket, array $data): array;

    // ── 桶管理 / 聚合 ─────────────────────────────────────────────────────

    /** 顶栏徽章缓存 TTL（秒），从 config 读，未配置回退到 30 */
    public function openCountCacheTtl(): int
    {
        $v = (int) ($this->config['cache_ttl'] ?? 30);

        return $v > 0 ? $v : 30;
    }

    /**
     * open 桶是否已达写盘上限(record() 新建分支的写盘闸)。
     *
     * 先看缓存计数；仅当缓存说「已满」时，再 countOpen() 实测复核一次，实测仍满才拦。
     * 因为 open 桶条数也会被本进程之外的路径减少 —— CloudSync::pruneLocal 直接 unlink open dormant 文件、
     * moo:monitor:migrate 直接 rename、云端回收等都不会 forgetOpenCountCache()。陈旧的偏大缓存会在接近上限时
     * 把本该落盘的新 hash 误判成桶满、return null 静默丢弃（最长一个 TTL）。实测复核把这条误判堵在临界点，
     * 代价仅是「接近满」这一罕见分支多一次 glob，高频热路径不受影响。
     */
    protected function openBucketFull(): bool
    {
        $max = (int) ($this->config['max_open'] ?? 500);

        return $this->cachedOpenCount() >= $max && $this->countOpen() >= $max;
    }

    /**
     * open 桶条数（缓存版），供 record() 的 max_open gate 用。
     * 写盘会 forgetOpenCountCache()，所以高频写时仍会 miss-then-glob；但稀疏新建 + 顶栏徽章读命中缓存，
     * 不必每次新 hash 都 glob 整个 open 桶。
     */
    protected function cachedOpenCount(): int
    {
        try {
            if (function_exists('cache')) {
                return (int) cache()->remember(
                    static::CACHE_OPEN_COUNT,
                    $this->openCountCacheTtl(),
                    fn () => $this->countOpen(),
                );
            }
        } catch (Throwable) {
            // cache 不可用 → 退回直接 glob
        }

        return $this->countOpen();
    }

    /**
     * 列表：按 last_seen desc。glob 桶目录实时聚合，不依赖 index.yaml。
     * status='all' 跳过 filter，返 open + resolved + deleted 合并。
     */
    public function list(string $status = 'open', int $limit = 100): array
    {
        $rows = [];
        foreach ($this->allRows() as $hash => $row) {
            if ($status !== 'all' && $row['status'] !== $status) {
                continue;
            }
            $row['hash'] = $hash;
            $rows[]      = $row;
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($b['last_seen'] ?? ''), (string) ($a['last_seen'] ?? '')));

        return array_slice($rows, 0, $limit);
    }

    public function count(?string $status = null): int
    {
        // 直接数桶内 .yaml 文件，不 parse(status === bucket 由原子 moveFile 保证)。
        $buckets = $status !== null ? [$status] : ['open', 'resolved', 'deleted'];
        $n       = 0;
        foreach ($buckets as $bucket) {
            $dir = $this->basePath . '/' . $bucket;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*.yaml') ?: [] as $file) {
                    if ($this->isValidHash(basename($file, '.yaml'))) {
                        $n++;
                    }
                }
            }
        }

        return $n;
    }

    public function get(string $hash): ?array
    {
        return $this->find($hash);
    }

    // 云端化（plan-33）后：处置（resolve/reopen/软删/恢复/purge）与清理（prune）统一在 S-Cloud 做，本地
    // 查看器 + moo:runtime:prune 均已退役 —— 原 resolve()/reopen()/delete()/restoreDeleted()/purge()/
    // purgeStatus()/pruneOlderThan()/pruneKeepLatest() 全无调用者，已删（死代码）。本地仅采集进 open/，经
    // moo:cloud:push 上云后由 CloudSync::pruneLocal 回收；moveFile(resolved→open) 仍保留作复发自动重开。
    // 留存的读 API:count()（顶栏/缓冲页）、get()/list()（读单条/列表，测试 + 内部用）。

    protected function ensureDir(): void
    {
        foreach (['open', 'resolved', 'deleted'] as $bucket) {
            $dir = $this->basePath . '/' . $bucket;
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        // storage/ 下的新顶层目录不被 Laravel 默认 .gitignore 覆盖 —— 首次建目录时落一个
        // 自我屏蔽的 .gitignore（纯 `*`，连自身一起屏蔽）：目录全程不进宿主 git,status 零噪音。
        $gitignore = $this->basePath . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
    }

    protected function path(string $hash, string $bucket): string
    {
        // hash 必 [a-f0-9]{12}。任何非法字符（/, .., null byte）直接丢 InvalidArgumentException —— 防 path
        // traversal(authed user 也不能漂出桶根)。CLI 命令（moo:*:prune）也走这层，enforcement 必须在此。
        if (! $this->isValidHash($hash)) {
            throw new \InvalidArgumentException('invalid record hash: ' . $hash);
        }

        return $this->basePath . '/' . $bucket . '/' . $hash . '.yaml';
    }

    protected function readFile(string $hash, string $bucket): ?array
    {
        $path = $this->path($hash, $bucket);
        if (! is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null; // I/O 错误（权限 / 文件刚被删等）— 静默返 null
        }
        if ($raw === '') {
            // 0 字节文件 — 数据腐败信号（写入中断 / 外部工具误写空 / 磁盘满）。留 log 让上层能发现，
            // 但仍返 null 保持兼容契约（避免抛错破坏 list/find/get）。
            if (function_exists('app')) {
                try {
                    app('log')->warning('moo-monitor: empty yaml detected (data corruption?)', [
                        'path' => $path, 'hash' => $hash, 'bucket' => $bucket,
                    ]);
                } catch (Throwable) {
                    // 日志 service 不可用也不让 readFile 抛错
                }
            }

            return null;
        }
        try {
            $data = Yaml::parse($raw);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 遍历 open / resolved / deleted 三桶下单条 yaml，实时聚合为 list/count/prune 用的派生 row。
     * 单条 yaml 是 SoT —— 取代旧 index.yaml「聚合 hot-spot」（多机 git sync 必然冲突的设计原罪）。
     *
     * @return \Generator<string, array>
     */
    protected function allRows(): \Generator
    {
        foreach (['open', 'resolved', 'deleted'] as $bucket) {
            $dir = $this->basePath . '/' . $bucket;
            if (! is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.yaml') ?: [] as $file) {
                $hash = basename($file, '.yaml');
                if (! $this->isValidHash($hash)) {
                    continue;
                }
                $data = $this->readFile($hash, $bucket);
                if ($data === null) {
                    continue;
                }
                yield $hash => $this->deriveRow($bucket, $data);
            }
        }
    }

    protected function forgetOpenCountCache(): void
    {
        try {
            if (function_exists('cache')) {
                cache()->forget(static::CACHE_OPEN_COUNT);
            }
        } catch (Throwable) {
            // ignore:CLI / 未启动 cache 时静默
        }
    }

    // ── 低层文件 IO 原语（原子写 + 三桶搬移）──────────────────────────────

    protected function writeFile(string $key, string $bucket, array $data, bool $isNew = false): bool
    {
        // 写盘前刷 meta.updated_at，让 ScaffoldMergeYamlCommand 在多端 push 冲突时 last-write-wins 合并
        $data['meta'] = array_merge($data['meta'] ?? [], ['updated_at' => $this->nowIso()]);
        $yaml         = Yaml::dump(
            $data,
            8,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE
        );
        // 原子写：先写同目录临时文件，再 rename 覆盖（同 fs 的 rename 原子）。
        // 防两种损坏：① 崩溃/磁盘满留半截或 0 字节 yaml；② 每分钟的 moo:cloud:push 进程读到
        // 正在被重写的文件 → 解析失败被静默跳过 → 那条变更可能再不重试（唯一真实丢数据路径）。
        // 并发同 hash 写各自用唯一临时名，rename 后最多 last-write-wins，但读者永远见到完整文件。
        $target = $this->path($key, $bucket);
        $tmp    = $target . '.tmp' . bin2hex(random_bytes(4));
        $ok     = false;
        if (@file_put_contents($tmp, $yaml) !== false) {
            if (@rename($tmp, $target)) {
                $ok = true;
            } else {
                @unlink($tmp);   // rename 失败（跨设备等罕见）→ 清理临时文件，原文件保持不动
            }
        }
        // 只有「新建文件」才改变 open 桶文件数 → 才需让 open 数缓存失效。
        // refresh 覆盖同名已存在文件不改变桶内文件数，在热点路径上 forget 是纯多余动作 ——
        // 多 worker 并发下任一进程一写就清缓存，会让所有进程的下一次新建都得 glob 整个 open 桶。
        if ($ok && $isNew) {
            $this->forgetOpenCountCache();
        }

        return $ok;
    }

    protected function moveFile(string $key, string $from, string $to): bool
    {
        $src = $this->path($key, $from);
        $dst = $this->path($key, $to);
        if (! is_file($src)) {
            return false;
        }
        $this->ensureDir();
        $ok = @rename($src, $dst);
        if ($ok) {
            $this->forgetOpenCountCache();
        }

        return $ok;
    }

    private function find(string $key): ?array
    {
        return $this->readFile($key, 'open')
            ?? $this->readFile($key, 'resolved')
            ?? $this->readFile($key, 'deleted');
    }

    /**
     * 返回该 hash 实际落在哪个桶（open → resolved → deleted 优先序），都不在返 null。
     * record() 用它判定复发应从哪个桶搬回 open —— 比读 yaml 内 status 字段更可靠（桶目录是 status 真源）。
     */
    protected function findBucket(string $key): ?string
    {
        foreach (['open', 'resolved', 'deleted'] as $bucket) {
            if (is_file($this->path($key, $bucket))) {
                return $bucket;
            }
        }

        return null;
    }

    private function countOpen(): int
    {
        $dir = $this->basePath . '/open';
        if (! is_dir($dir)) {
            return 0;
        }

        $n = 0;
        foreach (glob($dir . '/*.yaml') ?: [] as $file) {
            if ($this->isValidHash(basename($file, '.yaml'))) {
                $n++;
            }
        }

        return $n;
    }

    // ── 每日写盘上限（daily_cap）+ 冻结期溢出计数（P2-1）────────────────────

    /** daily_cap：同一 hash 每天最多写盘次数；<=0 不限制。默认 10 */
    protected function dailyCap(): int
    {
        return (int) ($this->config['daily_cap'] ?? 10);
    }

    /**
     * 当天写盘次数是否已达上限。达到 → record() 跳过写盘（不刷 last_seen / 不 +count / 不刷 meta.updated_at）。
     * 旧数据无 daily 字段 / daily.date 非今天 → 视为未达上限（次日翻篇归零）。
     */
    protected function dailyCapReached(array $existing, string $now): bool
    {
        $cap = $this->dailyCap();
        if ($cap <= 0) {
            return false;
        }
        $daily = $existing['daily'] ?? null;
        if (! is_array($daily) || ($daily['date'] ?? null) !== $this->today($now)) {
            return false;
        }

        return (int) ($daily['count'] ?? 0) >= $cap;
    }

    /** 递增当天计数；跨天（或旧数据无 daily）则归零重计为 1 */
    protected function bumpDaily(?array $daily, string $now): array
    {
        $today = $this->today($now);
        if (is_array($daily) && ($daily['date'] ?? null) === $today) {
            return ['date' => $today, 'count' => (int) ($daily['count'] ?? 0) + 1];
        }

        return ['date' => $today, 'count' => 1];
    }

    /** 从 ISO-8601 取日期段 YYYY-MM-DD，沿用 last_seen 的本地时区 */
    protected function today(string $iso): string
    {
        return substr($iso, 0, 10);
    }

    /** 冻结期溢出计数 +1（best-effort；cache 不可用则丢失，退回现状偏低）。 */
    protected function bumpDailyOverflow(string $hash): void
    {
        try {
            if (function_exists('cache')) {
                $key = $this->overflowKey($hash);
                // add 只在 key 不存在时落一个带 TTL 的 0，把 TTL 锚定到次日凌晨 1 点；随后 increment 累加。
                cache()->add($key, 0, $this->overflowTtl());
                cache()->increment($key);
            }
        } catch (Throwable) {
            // best-effort：cache 后端不可用 → 溢出计数丢失，次日回填偏低（P1-7② 决议，不做本地文件计数器兜底）。
        }
    }

    /** 读出并清空该 hash 的溢出计数（次日首次通过 cap 闸写盘时回填进 count）。cache 不可用 → 0。 */
    protected function drainDailyOverflow(string $hash): int
    {
        try {
            if (function_exists('cache')) {
                $key = $this->overflowKey($hash);
                $n   = (int) cache()->get($key, 0);
                if ($n > 0) {
                    cache()->forget($key);
                }

                return $n;
            }
        } catch (Throwable) {
            // best-effort：cache 不可用 → 无回填
        }

        return 0;
    }

    /** overflow 缓存 key：复用各类型 open_count 缓存前缀（runtime / sql_slow 各自独立）。 */
    private function overflowKey(string $hash): string
    {
        return str_replace(':open_count', '', static::CACHE_OPEN_COUNT) . ':overflow:' . $hash;
    }

    /** overflow key TTL（秒）到次日凌晨 1 点：当天溢出累积同一 key，次日回填后清；回填前过期只退回偏低。 */
    private function overflowTtl(): int
    {
        $ttl = (int) (strtotime('tomorrow 01:00') - time());

        return $ttl > 0 ? $ttl : 3600;
    }

    // ── 工具（子类共用）───────────────────────────────────────────────────

    protected function relPath(string $abs): string
    {
        if (! function_exists('base_path')) {
            return $abs;
        }
        $base = rtrim(base_path(), '/');
        if (str_starts_with($abs, $base)) {
            return ltrim(substr($abs, strlen($base)), '/');
        }

        return $abs;
    }

    protected function isValidHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{12}$/', $hash) === 1;
    }

    protected function nowIso(): string
    {
        // 毫秒精度 ISO-8601(原 date('c') 只到秒)。秒级 + 游标 `<=` 比较会在「push 完成的同一秒内又写入」时
        // 把该次变更永久跳过、甚至让 prune 误删未上云的 resolve(red-team 三家都指到这个根因)。毫秒精度后边界碰撞
        // 概率趋零。格式仍 ISO-8601 兼容：strtotime / 字符串排序 / 旧无毫秒数据都照常解析。
        return (new \DateTimeImmutable)->format('Y-m-d\TH:i:s.vP');
    }

    protected function firstLine(string $s): string
    {
        $line = strtok($s, "\n") ?: $s;

        return substr(trim($line), 0, 160);
    }

    protected function extractUserName($user): ?string
    {
        if ($user === null) {
            return null;
        }
        foreach (['real_name', 'name', 'username', 'mobile', 'email'] as $key) {
            if (isset($user->{$key})) {
                return (string) $user->{$key};
            }
        }

        return null;
    }

    /**
     * 解析当前 Request（console 语境感知，失真 C）。
     *
     * console / 队列 worker 下 request() 从容器解析出的是**空 Request 对象而非 null** —— 直接用它会把
     * CLI 语境的异常 / 慢查询误标成 GET http://…（extractRequest 的 CLI 分支几乎不可达）。故
     * runningInConsole 时返回 null，走真正的 CLI 分支；HTTP 语境才解析真实 request。显式传入优先（调用方 ??=）。
     */
    protected function resolveRequest(): ?\Illuminate\Http\Request
    {
        if (function_exists('app') && app()->runningInConsole()) {
            return null;
        }

        return function_exists('request') ? request() : null;
    }
}
