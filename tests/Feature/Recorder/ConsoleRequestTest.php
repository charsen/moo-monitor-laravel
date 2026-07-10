<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * P2-3 回归锁：console 语境 request 误标（失真 C）。
 *
 * 已证实（修前）：console / 队列 worker 下 request() 解析出**空 Request 对象而非 null**，
 * 把 CLI 语境的异常/慢查询误标成 GET http://…。修后：runningInConsole 时取 null → 真正的 CLI 分支；
 * 显式传入的 request 仍优先。testbench 测试本身即 console 语境（runningInConsole()=true）。
 */
function rmConsoleReq(string $base): void
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

it('前提：当前就是 console 语境', function () {
    expect(app()->runningInConsole())->toBeTrue();
});

it('runtime：console 下无显式 request → method=CLI、url=null（不再误标 GET）', function () {
    $base = sys_get_temp_dir() . '/rt_cli_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $data = $rec->get($rec->record(new RuntimeException('cli boom')));
        expect($data['request']['method'])->toBe('CLI');
        expect($data['request']['url'])->toBeNull();
    } finally {
        rmConsoleReq($base);
    }
});

it('slow sql：console 下无显式 request → method=CLI', function () {
    $base = sys_get_temp_dir() . '/sql_cli_' . uniqid();
    $rec  = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0]);
    try {
        $rec->record('select 1', 'select 1', 200.0, '/app/F.php', 5);
        $data = $rec->get($rec->list('open')[0]['hash']);
        expect($data['request']['method'])->toBe('CLI');
    } finally {
        rmConsoleReq($base);
    }
});

it('显式传入的 request 仍优先（即便 console 语境）', function () {
    $base = sys_get_temp_dir() . '/rt_expl_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $req  = Request::create('http://localhost/api/x?a=1', 'POST');
        $data = $rec->get($rec->record(new RuntimeException('explicit req'), $req));
        expect($data['request']['method'])->toBe('POST');
        expect($data['request']['url'])->toContain('/api/x');
    } finally {
        rmConsoleReq($base);
    }
});
