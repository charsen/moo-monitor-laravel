<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2026-06-04
 * @Description: 把本地 runtime / 慢 SQL 记录推送到 moo-scaffold-cloud
 *
 * 用法：
 *   moo:cloud:push                     增量推送 runtimes + slow_sql(默认)
 *   moo:cloud:push --type=runtimes     只推 runtime 错误
 *   moo:cloud:push --type=slow_sql     只推慢 SQL
 *   moo:cloud:push --all               忽略游标,全量重推(修数据/换云端时用)
 *   moo:cloud:push --dry-run           只统计待推条数,不真正发请求
 *
 * 适合接 cron / Laravel scheduler 自动执行;enabled + schedule 同时为真时,
 * MonitorProvider 已自动挂每分钟调度(需宿主跑 schedule:run)。
 */

namespace Mooeen\Monitor\Command;

use Illuminate\Console\Command;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Cloud\CloudSync;

class CloudPushCommand extends Command
{
    protected $name = 'moo:cloud:push';

    protected $description = '把本地 runtime / 慢 SQL 记录推送到 moo-scaffold-cloud（增量、幂等）';

    protected $signature = 'moo:cloud:push
        {--type=both : 推送类型:runtimes / slow_sql / both}
        {--all : 忽略游标,全量重推}
        {--dry-run : 只统计待推条数,不真正发请求}';

    public function handle(): int
    {
        $this->warnLegacyEnv();

        $cfg = (array) config('moo-monitor.cloud', []);

        if (! ($cfg['enabled'] ?? false)) {
            $this->warn('cloud 推送未启用（MOO_MONITOR_CLOUD_ENABLED=false），跳过。');

            return self::SUCCESS;
        }
        if (empty($cfg['base_url']) || empty($cfg['token'])) {
            $this->error('MOO_MONITOR_CLOUD_TOKEN 未配置（URL 已有默认值），无法推送。');

            return self::INVALID;
        }

        $type = (string) $this->option('type');
        if (! in_array($type, ['both', 'runtimes', 'slow_sql'], true)) {
            $this->error('--type 必须是 runtimes / slow_sql / both 之一。');

            return self::INVALID;
        }

        $all       = (bool) $this->option('all');
        $dryRun    = (bool) $this->option('dry-run');
        $retention = (int) ($cfg['local_retention_days'] ?? 7);
        $sync      = new CloudSync;

        $targets = $type === 'both' ? $sync->types() : [$type];
        $rows    = [];
        $failed  = false;

        foreach ($targets as $t) {
            $r = $sync->sync($t, $all, $dryRun);

            if ($r['skipped']) {
                $status = '跳过：' . $r['reason'];
            } elseif (! $r['ok']) {
                $status = '失败：' . $r['error'];
                $failed = true;
            } elseif ($dryRun) {
                $status = '待推 ' . $r['changed'] . ' 条（dry-run）';
            } else {
                $status = $r['pushed'] > 0 ? "已推 {$r['pushed']} 条 / {$r['batches']} 批" : '无变化';
            }

            // 推送成功后回收本地(临时缓冲);失败/跳过/dry-run 一律不动,确保只清已上云的。
            if (! $dryRun && $r['ok'] && ! $r['skipped']) {
                $p        = $sync->pruneLocal($t, $retention);
                $recycled = $p['purged'] + $p['prunedOpen'];
                if ($recycled > 0) {
                    $status .= " · 本地回收 {$recycled}";
                }
            }

            $rows[] = [$t, $r['scanned'], $r['changed'], $r['pushed'], $status];
        }

        // 心跳:每次真实跑(非 dry-run)打一拍 —— 哪怕这轮无变化也算「推送管道还活着」,
        // 云端的「推送中断」哨兵据此区分「项目健康沉默」与「采集/推送真断了」。best-effort,
        // 失败不影响推送结果。
        if (! $dryRun) {
            (new CloudClient($cfg))->heartbeat();
        }

        $this->table(['类型', '扫描', '变更', '推送', '结果'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * scaffold ≤3.8 时代的 SCAFFOLD_CLOUD_* env 已更名 MOO_MONITOR_CLOUD_*,不做兼容回落。
     * 检测到旧名已配、新名为空时提示一次,帮存量宿主定位「升级后推送突然没配置」的原因。
     */
    private function warnLegacyEnv(): void
    {
        if (env('SCAFFOLD_CLOUD_TOKEN') !== null && (string) env('MOO_MONITOR_CLOUD_TOKEN', '') === '') {
            $this->warn('检测到旧配置 SCAFFOLD_CLOUD_*：moo-monitor-laravel 使用 MOO_MONITOR_CLOUD_* 命名,');
            $this->warn('旧变量不再生效,请在 .env 中改名(对照表见 moo-monitor-laravel README)。');
        }
    }
}
