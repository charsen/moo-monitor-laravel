<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Cloud\CloudClient;

/**
 * moo:cloud:test 自检命令:验证「配置检查 → 心跳 → 推 runtime → 推慢 SQL」整条管道的 wiring。
 *   - 未配置:INVALID 早退,不打网络
 *   - 心跳失败:FAILURE,不推任何记录
 *   - 全绿:两条 intake 各收到 1 条带 token 的记录,默认不 resolve(保留「未处理」),退出 0
 *   - --resolve:推送后才 resolve;--type:只测指定类型
 *   - 云端缺确认计数 / 计数不闭合:视为失败(承接 #10 fail-closed)
 */
function cloudTest_configure(): void
{
    config([
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_' . str_repeat('a1', 20),
        'moo-monitor.cloud.timeout'  => 5,
        'moo-monitor.cloud.verify'   => true,
    ]);
}

function cloudTest_fakeAllOk(): void
{
    Http::fake([
        'cloud.test/api/v1/heartbeat'           => Http::response(['ok' => true], 200),
        'cloud.test/api/v1/runtimes/intake'     => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0], 200),
        'cloud.test/api/v1/runtimes/resolve'    => Http::response(['ok' => true, 'runtime' => ['status' => 'resolved']], 200),
        'cloud.test/api/v1/slow-queries/intake' => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0], 200),
    ]);
}

it('未配置 base_url / token → INVALID,不打网络', function () {
    config(['moo-monitor.cloud.enabled' => true, 'moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);
    Http::fake();

    $this->artisan('moo:cloud:test')
        ->expectsOutputToContain('未配置')
        ->assertExitCode(2);

    Http::assertNothingSent();
});

it('心跳失败 → FAILURE,不推任何 intake', function () {
    cloudTest_configure();
    Http::fake([
        'cloud.test/api/v1/heartbeat' => Http::response(['ok' => false, 'error' => 'token 无效'], 403),
    ]);

    $this->artisan('moo:cloud:test')->assertExitCode(1);

    Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/intake'));
});

it('全绿 → 两条 intake 各 1 条带 token,默认不 resolve(保留未处理),退出 0', function () {
    cloudTest_configure();
    cloudTest_fakeAllOk();

    $this->artisan('moo:cloud:test')
        ->expectsOutputToContain('自检通过')
        ->assertExitCode(0);

    // runtime intake:1 条记录 + token,且是自检异常类
    Http::assertSent(function ($req) {
        if (! str_contains((string) $req->url(), '/api/v1/runtimes/intake')) {
            return false;
        }
        $data = $req->data();

        return ($data['token'] ?? null) !== null
            && count($data['records'] ?? []) === 1
            && str_contains($data['records'][0]['exception']['class'] ?? '', 'SelfTestException');
    });

    // slow-queries intake:1 条记录
    Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/api/v1/slow-queries/intake')
        && count($req->data()['records'] ?? []) === 1);

    // 默认保留「未处理」:绝不自动 resolve
    Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/api/v1/runtimes/resolve'));
});

it('--resolve → 推送后才在云端 resolve runtime', function () {
    cloudTest_configure();
    cloudTest_fakeAllOk();

    $this->artisan('moo:cloud:test', ['--resolve' => true])->assertExitCode(0);

    Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/api/v1/runtimes/resolve'));
});

it('--type=runtimes → 只推 runtime,不碰慢 SQL', function () {
    cloudTest_configure();
    cloudTest_fakeAllOk();

    $this->artisan('moo:cloud:test', ['--type' => 'runtimes'])->assertExitCode(0);

    Http::assertSent(fn ($req) => str_contains((string) $req->url(), '/api/v1/runtimes/intake'));
    Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), '/api/v1/slow-queries/intake'));
});

it('云端 intake 缺确认计数字段 → 自检失败(fail-closed)', function () {
    cloudTest_configure();
    Http::fake([
        'cloud.test/api/v1/heartbeat'           => Http::response(['ok' => true], 200),
        'cloud.test/api/v1/runtimes/intake'     => Http::response(['ok' => true, 'saved' => 1], 200), // 缺 filtered / skipped
        'cloud.test/api/v1/slow-queries/intake' => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0], 200),
    ]);

    $this->artisan('moo:cloud:test', ['--type' => 'runtimes'])->assertExitCode(1);
});

it('自检记录形状合法:runtime/slow 各字段齐全可被云端 intake 解析', function () {
    cloudTest_configure();
    cloudTest_fakeAllOk();

    $this->artisan('moo:cloud:test')->assertExitCode(0);

    Http::assertSent(function ($req) {
        if (! str_contains((string) $req->url(), '/api/v1/runtimes/intake')) {
            return false;
        }
        $rec = $req->data()['records'][0] ?? [];

        return isset($rec['hash'], $rec['exception'], $rec['context'], $rec['meta']['updated_at']);
    });
    Http::assertSent(function ($req) {
        if (! str_contains((string) $req->url(), '/api/v1/slow-queries/intake')) {
            return false;
        }
        $rec = $req->data()['records'][0] ?? [];

        return isset($rec['hash'], $rec['sql']['raw'], $rec['took']['max_ms'], $rec['meta']['updated_at']);
    });

    expect(CloudClient::PATH_SLOW_QUERIES)->toBe('api/v1/slow-queries/intake');
});
