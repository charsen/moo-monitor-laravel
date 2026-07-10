<?php declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * P1-6 回归锁：
 *   ① 慢 SQL 补采 connection（矩阵 #14）—— listener 透传 $event->connectionName，落 at.connection + deriveRow；
 *   ② 调用栈 app_frames 前缀配置化（失真 F）—— extractTrace 读 runtime.app_frame_prefixes，不再硬编码 app/ + routes/。
 */
function rmP16Buckets(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @unlink($base . '/.gitignore');
    @rmdir($base);
}

it('慢 SQL 落 at.connection，deriveRow 一并带上 connection', function () {
    $base = sys_get_temp_dir() . '/sql_conn_' . uniqid();
    // listener 的开关 / 阈值读全局 config（recorder 落盘另读构造 config）
    config()->set('moo-monitor.sql_slow.enabled', true);
    config()->set('moo-monitor.sql_slow.threshold_ms', 100);
    $rec = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 100]);
    try {
        $conn     = app('db')->connection();
        $connName = $conn->getName();

        (new SqlSlowListener($rec))->handle(
            new QueryExecuted('select * from `t` where `id` = ?', [1], 250.0, $conn)
        );

        $rows = $rec->list('open');
        expect($rows)->toHaveCount(1);
        expect($rows[0]['connection'])->toBe($connName);

        $data = $rec->get($rows[0]['hash']);
        expect($data['at']['connection'])->toBe($connName);
    } finally {
        rmP16Buckets($base);
    }
});

it('extractTrace app_frames 按 app_frame_prefixes 配置过滤', function () {
    $base = sys_get_temp_dir() . '/rt_prefix_' . uniqid();
    // 测试文件在 base_path 之外 → relPath 返回绝对路径（'/' 开头）；用它验证配置真的驱动过滤。
    $e = new RuntimeException('trace me');

    $rMatch = new RuntimeErrorRecorder($base . '_a', ['enabled' => true, 'app_frame_prefixes' => ['/']]);
    $rNone  = new RuntimeErrorRecorder($base . '_b', ['enabled' => true, 'app_frame_prefixes' => ['zzz_no_such_prefix/']]);
    try {
        $matched = $rMatch->get($rMatch->record($e))['trace']['app_frames'];
        expect($matched)->not->toBeEmpty();
        foreach ($matched as $f) {
            expect($f['file'])->toStartWith('/');
            expect($f)->toHaveKeys(['file', 'line', 'function']);
        }

        $none = $rNone->get($rNone->record($e))['trace']['app_frames'];
        expect($none)->toBe([]);
    } finally {
        rmP16Buckets($base . '_a');
        rmP16Buckets($base . '_b');
    }
});
