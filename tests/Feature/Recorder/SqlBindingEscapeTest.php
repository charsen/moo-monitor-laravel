<?php declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * P2-4 回归锁：fillBindings 转义（失真 D，兼脱敏安全）。
 *
 * binding 里的裸单引号/反斜杠若不转义，会破坏 sql_last 的引号结构，让 maskSensitiveSql 的引号串正则错位、
 * 把敏感列的值漏脱敏。转义后引号结构合法、敏感值即使含引号也被替成 ***。
 */
function rmBindEsc(string $base): void
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

it('binding 含单引号/反斜杠 → 转义合法、敏感列即使带引号仍被脱敏', function () {
    config()->set('moo-monitor.sql_slow.enabled', true);
    config()->set('moo-monitor.sql_slow.threshold_ms', 0);

    $base = sys_get_temp_dir() . '/sql_bind_' . uniqid();
    $rec  = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0, 'mask_keys' => ['password']]);
    try {
        (new SqlSlowListener($rec))->handle(new QueryExecuted(
            'update `users` set `password` = ?, `note` = ? where `id` = ?',
            ["s3cr3t'val\\x", "O'Brien", 5],
            200.0,
            app('db')->connection()
        ));

        $last = $rec->get($rec->list('open')[0]['hash'])['sql']['last'];

        // 敏感列的值即使含引号也被整体脱敏（不因引号错位而漏）
        expect($last)->toContain('`password` = ***');
        expect($last)->not->toContain('s3cr3t');
        // 非敏感列的值转义后引号结构合法：note = 'O\'Brien'
        expect($last)->toContain("`note` = 'O\\'Brien'");
    } finally {
        rmBindEsc($base);
    }
});
