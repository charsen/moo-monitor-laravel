<?php declare(strict_types=1);

namespace Mooeen\Monitor\Recorder\Concerns;

use Throwable;

/**
 * 每日写盘上限（daily_cap）。同一 hash 当天复发达到上限后，record() 直接返回、不再写盘 ——
 * yaml 内容冻结，meta.updated_at 不再刷新。两层收益：① 止住多端 git churn（历史）；
 * ② 云端化后，避免热记录每分钟被 moo:cloud:push 反复推（冻结即 mtime 不变、过游标）。
 *
 * 计数真实性（P2-1）：冻结期不再让 count 一起冻死 —— 每次被 cap 拦下都 cache increment 一个 overflow
 * 计数器；次日该 hash 首次通过 cap 闸写盘时把 overflow 回填进 count（count += overflow + 1）。
 * cache 后端不可用/被清 → 溢出计数丢失、退回「count 偏低」现状（best-effort，P1-7② 决议：不引入
 * 本地文件计数器，热路径加写盘 IO 违背「采集绝不拖垮宿主」）。
 *
 * RuntimeErrorRecorder / SqlSlowRecorder 共用。依赖宿主类有 $this->config['daily_cap'] 与 CACHE_OPEN_COUNT。
 */
trait TracksDailyCap
{
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

    /** 从 ISO-8601(date('c'))取日期段 YYYY-MM-DD，沿用 last_seen 的本地时区 */
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
        return str_replace(':open_count', '', self::CACHE_OPEN_COUNT) . ':overflow:' . $hash;
    }

    /** overflow key TTL（秒）到次日凌晨 1 点：当天溢出累积同一 key，次日回填后清；回填前过期只退回偏低。 */
    private function overflowTtl(): int
    {
        $ttl = (int) (strtotime('tomorrow 01:00') - time());

        return $ttl > 0 ? $ttl : 3600;
    }
}
