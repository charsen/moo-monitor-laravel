<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * 3.7.1 回归锁(异常通知移交云端后的两处修整):
 *
 * #2 写盘真失败时 record() 必须返回 null —— 旧实现 @file_put_contents 静默失败却照样返回 hash
 *    (误报成功),而 ExceptionDispatcher 又对 null 一律去"探目录",刚好探在不该探的跳过分支上。
 *    现在:writeFile() 返回 bool,recorder 仅在真失败时返回 null + 记一次诊断。
 * #3 总开关统一走 runtime.enabled —— 关掉就该彻底静默(连目录都不建),不再有 exception.enabled 那层。
 */
it('写盘失败时 record() 返回 null(不误报成功)', function () {
    // basePath 落在一个普通文件下 → open 目录建不出来 → 写盘必失败
    $file = tempnam(sys_get_temp_dir(), 'rt_fail_');
    $base = $file . '/nested';
    $r    = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $hash = $r->record(new RuntimeException('boom unwritable'));
        expect($hash)->toBeNull();
    } finally {
        @unlink($file);
    }
});

it('runtime.enabled=false 时 record() 直接返回 null,且不建目录/落盘', function () {
    $base = sys_get_temp_dir() . '/rt_off_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['enabled' => false]);
    try {
        $hash = $r->record(new RuntimeException('off'));
        expect($hash)->toBeNull();
        expect(is_dir($base . '/open'))->toBeFalse();
    } finally {
        @rmdir($base . '/open');
        @rmdir($base);
    }
});

it('SqlSlow:写盘失败时 record() 返回 null(不误报成功)', function () {
    // basePath 落普通文件下 → open 目录建不出来 → 写盘必失败(跟 Runtime 同断言,锁住 SqlSlow 不再静默假成功)
    $file = tempnam(sys_get_temp_dir(), 'sq_fail_');
    $base = $file . '/nested';
    $r    = new SqlSlowRecorder($base, ['enabled' => true]);
    try {
        $hash = $r->record('select * from `t` where `id` = ?', 'select * from `t` where `id` = 1', 200.0, '/app/Foo.php', 42);
        expect($hash)->toBeNull();
    } finally {
        @unlink($file);
    }
});
