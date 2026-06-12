<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;

/**
 * moo:cloud:push 命令 wiring + 守卫分支测试。编排细节由 CloudSyncTest 覆盖,这里只验:
 * 命令已注册、未启用/未配置的早退、--dry-run 端到端跑通(不打网络、不写游标)。
 */
beforeEach(function () {
    $this->origStorage = storage_path();
    $this->sandbox     = sys_get_temp_dir() . '/monitor_cloudcmd_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    app()->useStoragePath($this->sandbox);
    config(['moo-monitor.runtime.enabled' => true]);
    app()->forgetInstance(RuntimeErrorRecorder::class);
});

afterEach(function () {
    app()->useStoragePath($this->origStorage);
    cloudCmd_rrmdir($this->sandbox);
});

function cloudCmd_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? cloudCmd_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

it('cloud 未启用 → 友好提示 + 退出码 0(cron 不报错)', function () {
    config(['moo-monitor.cloud.enabled' => false]);
    Http::fake();

    $this->artisan('moo:cloud:push')
        ->expectsOutputToContain('未启用')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('启用但缺 base_url / token → 退出码 INVALID(2)', function () {
    config(['moo-monitor.cloud.enabled' => true, 'moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);
    Http::fake();

    $this->artisan('moo:cloud:push')
        ->expectsOutputToContain('未配置')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('--type 非法 → 退出码 INVALID(2)', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
    ]);

    $this->artisan('moo:cloud:push --type=nope')->assertExitCode(2);
});

it('--dry-run 端到端跑通:不打网络、退出码 0', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
    ]);
    Http::fake();

    app(RuntimeErrorRecorder::class)->record(new RuntimeException('boom')); // 落一条到沙盒桶

    $this->artisan('moo:cloud:push --type=runtimes --dry-run')->assertExitCode(0);

    Http::assertNothingSent(); // dry-run 不发请求
});

it('每次真实跑都打一拍心跳:即便无变化也 POST /api/v1/heartbeat', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
    ]);
    Http::fake(['*' => Http::response(['ok' => true])]);

    // 沙盒空桶 → 无记录可推,但命令仍应打一拍心跳(云端据此判推送管道存活)。
    $this->artisan('moo:cloud:push --type=runtimes')->assertExitCode(0);

    Http::assertSent(fn ($req) => str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/heartbeat'));
});

it('--dry-run 不打心跳', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
    ]);
    Http::fake();

    $this->artisan('moo:cloud:push --type=runtimes --dry-run')->assertExitCode(0);

    Http::assertNothingSent();
});

it('推送成功后回收本地:resolved 桶被清', function () {
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
    ]);
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    app(RuntimeErrorRecorder::class)->record(new RuntimeException('boom')); // open
    $dir = storage_path('moo-monitor/runtimes/resolved');
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/abababababab.yaml', "hash: abababababab\nstatus: resolved\nlast_seen: '" . now()->toIso8601String() . "'\ncount: 1\n");

    $this->artisan('moo:cloud:push --type=runtimes --all')->assertExitCode(0);

    expect(is_file($dir . '/abababababab.yaml'))->toBeFalse(); // 推送后被回收
});

it('旧 SCAFFOLD_CLOUD_* env 残留且新名未配 → 提示改名(不回落)', function () {
    config(['moo-monitor.cloud.enabled' => false]);
    // env() 读运行时环境变量:模拟存量宿主只配了旧名
    putenv('SCAFFOLD_CLOUD_TOKEN=legacy-token');
    putenv('MOO_MONITOR_CLOUD_TOKEN');

    try {
        $this->artisan('moo:cloud:push')
            ->expectsOutputToContain('SCAFFOLD_CLOUD_*')
            ->assertExitCode(0);
    } finally {
        putenv('SCAFFOLD_CLOUD_TOKEN');
    }
});
