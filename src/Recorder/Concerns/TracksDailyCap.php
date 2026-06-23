<?php declare(strict_types=1);

namespace Mooeen\Monitor\Recorder\Concerns;

/**
 * 每日写盘上限（daily_cap）。同一 hash 当天复发达到上限后，record() 直接返回、不再写盘 ——
 * yaml 内容冻结，meta.updated_at 不再刷新。两层收益：① 止住多端 git churn（历史）；
 * ② 云端化后，避免热记录每分钟被 moo:cloud:push 反复推（冻结即 mtime 不变、过游标）。
 *
 * RuntimeErrorRecorder / SqlSlowRecorder 共用。依赖宿主类有 $this->config['daily_cap']。
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
}
