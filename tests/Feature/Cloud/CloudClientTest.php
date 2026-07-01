<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Cloud;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Tests\TestCase;

/**
 * CloudClient::fetchSummary —— scaffold 首页回拉云端汇总的只读拉取。
 *   - 未配置:不发请求、返回 ok:false
 *   - 正常响应:解析成 data,请求带 token + limit、命中 /api/v1/summary
 *   - 非 ok / 连接异常:返回 ok:false,绝不抛
 */
class CloudClientTest extends TestCase
{
    private function configureCloud(): void
    {
        config([
            'moo-monitor.cloud.base_url' => 'https://cloud.test',
            'moo-monitor.cloud.token'    => 'tok-' . str_repeat('a1', 20), // ≥32, 含字母+数字
            'moo-monitor.cloud.timeout'  => 5,
            'moo-monitor.cloud.verify'   => true,
        ]);
    }

    public function test_not_configured_returns_error_without_request(): void
    {
        Http::fake();
        config(['moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);

        $r = (new CloudClient)->fetchSummary();

        $this->assertFalse($r['ok']);
        $this->assertNull($r['data']);
        Http::assertNothingSent();
    }

    public function test_fetch_summary_parses_ok_response(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/summary' => Http::response([
                'ok'      => true,
                'project' => ['slug' => 'wc'],
                'stats'   => ['runtimes' => ['open' => 2, 'total' => 3]],
                'recent'  => ['runtimes' => [], 'slow_queries' => [], 'todos' => []],
            ], 200),
        ]);

        $r = (new CloudClient)->fetchSummary(5);

        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['data']['stats']['runtimes']['open']);
        $this->assertNull($r['error']);

        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/summary')
                && str_contains($req->body(), '"token"')
                && ($req->data()['limit'] ?? null) === 5;
        });
    }

    public function test_non_ok_response_is_failure_not_throw(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/summary' => Http::response(['ok' => false, 'error' => 'token 无此权限'], 403),
        ]);

        $r = (new CloudClient)->fetchSummary();

        $this->assertFalse($r['ok']);
        $this->assertNull($r['data']);
        $this->assertSame(403, $r['status']);
        $this->assertSame('token 无此权限', $r['error']);
    }

    public function test_connection_exception_is_swallowed(): void
    {
        $this->configureCloud();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $r = (new CloudClient)->fetchSummary();

        $this->assertFalse($r['ok']);
        $this->assertNull($r['data']);
        $this->assertNotNull($r['error']);
    }

    public function test_send_treats_partial_saved_count_as_failure(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/runtimes/intake' => Http::response(['ok' => true, 'saved' => 1], 200),
        ]);

        $r = (new CloudClient)->send(CloudClient::PATH_RUNTIMES, [
            ['hash' => 'aaaaaaaaaaaa'],
            ['hash' => 'bbbbbbbbbbbb'],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['saved']);
        $this->assertSame('saved 1/2', $r['error']);
    }

    public function test_send_treats_missing_saved_field_as_failure(): void
    {
        // 审查 #10:云端 2xx + ok:true 但缺 saved 字段时,不能乐观默认成「全部成功」前进游标(=丢数据)。
        // 缺字段 → saved=-1(records 非空,永不等于 count)→ ok=false → 不前进游标 → 下轮幂等重推。
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/runtimes/intake' => Http::response(['ok' => true], 200),
        ]);

        $r = (new CloudClient)->send(CloudClient::PATH_RUNTIMES, [
            ['hash' => 'aaaaaaaaaaaa'],
            ['hash' => 'bbbbbbbbbbbb'],
        ]);

        $this->assertFalse($r['ok']);
        $this->assertSame(-1, $r['saved']);
    }

    // ---- runtime 读/写(供 moo:cloud:mcp) ----------------------------------

    public function test_fetch_runtimes_hits_list_with_token_and_filters(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/runtimes/list' => Http::response([
                'ok' => true, 'count' => 1, 'runtimes' => [['hash' => 'abc123abc123', 'exc_class' => 'E']],
            ], 200),
        ]);

        $r = (new CloudClient)->fetchRuntimes(7, 'open');

        $this->assertTrue($r['ok']);
        $this->assertSame('abc123abc123', $r['data']['runtimes'][0]['hash']);

        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/runtimes/list')
                && str_contains($req->body(), '"token"')
                && ($req->data()['limit'] ?? null)  === 7
                && ($req->data()['status'] ?? null) === 'open';
        });
    }

    public function test_fetch_runtimes_omits_status_when_null(): void
    {
        $this->configureCloud();
        Http::fake(['cloud.test/api/v1/runtimes/list' => Http::response(['ok' => true, 'runtimes' => []], 200)]);

        (new CloudClient)->fetchRuntimes(20);

        Http::assertSent(fn ($req) => ! array_key_exists('status', $req->data()));
    }

    public function test_fetch_runtime_hits_get_with_hash(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/runtimes/get' => Http::response([
                'ok' => true, 'runtime' => ['hash' => 'abc123abc123', 'markdown' => '# 运行时错误'],
            ], 200),
        ]);

        $r = (new CloudClient)->fetchRuntime('abc123abc123', true);

        $this->assertTrue($r['ok']);
        $this->assertSame('# 运行时错误', $r['data']['runtime']['markdown']);

        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/runtimes/get')
                && ($req->data()['hash'] ?? null)         === 'abc123abc123'
                && ($req->data()['with_payload'] ?? null) === true;
        });
    }

    public function test_resolve_runtime_posts_note_and_swallows_failure(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/runtimes/resolve' => Http::response(['ok' => true, 'runtime' => ['status' => 'resolved']], 200),
        ]);

        $r = (new CloudClient)->resolveRuntime('abc123abc123', '已修', 'me');

        $this->assertTrue($r['ok']);
        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/runtimes/resolve')
                && ($req->data()['hash'] ?? null)        === 'abc123abc123'
                && ($req->data()['note'] ?? null)        === '已修'
                && ($req->data()['resolved_by'] ?? null) === 'me';
        });
    }

    public function test_runtime_methods_not_configured_return_error_without_request(): void
    {
        Http::fake();
        config(['moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);

        $client = new CloudClient;
        $this->assertFalse($client->fetchRuntimes()['ok']);
        $this->assertFalse($client->fetchRuntime('abc123abc123')['ok']);
        $this->assertFalse($client->resolveRuntime('abc123abc123')['ok']);
        Http::assertNothingSent();
    }

    // ---- 心跳(供 moo:cloud:push;云端「推送中断」哨兵据此判管道存活)----------

    public function test_heartbeat_hits_endpoint_with_token_and_returns_true(): void
    {
        $this->configureCloud();
        Http::fake(['cloud.test/api/v1/heartbeat' => Http::response(['ok' => true, 'at' => '2026-06-07T00:00:00+00:00'], 200)]);

        $this->assertTrue((new CloudClient)->heartbeat());

        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/heartbeat')
                && str_contains($req->body(), '"token"');
        });
    }

    public function test_heartbeat_sends_runtime_metadata(): void
    {
        $this->configureCloud();
        config([
            'app.name'                        => 'HostApp',
            'app.env'                         => 'production',
            'moo-monitor.cloud.enabled'       => true,
            'moo-monitor.runtime.enabled'     => true,
            'moo-monitor.sql_slow.enabled'    => false,
            'moo-monitor.cloud.push.runtimes' => true,
            'moo-monitor.cloud.push.slow_sql' => false,
            'moo-monitor.cloud.schedule'      => true,
        ]);
        Http::fake(['cloud.test/api/v1/heartbeat' => Http::response(['ok' => true], 200)]);

        $this->assertTrue((new CloudClient)->heartbeat());

        Http::assertSent(function ($req) {
            $meta = $req->data()['meta'] ?? [];

            return ($meta['sdk'] ?? null) === 'moo-monitor-laravel'
                && ($meta['sdk_version'] ?? null) !== null
                && ($meta['app_name'] ?? null)        === 'HostApp'
                && ($meta['app_env'] ?? null)         === 'production'
                && ($meta['runtime_enabled'] ?? null) === true
                && ($meta['push_slow_sql'] ?? null)   === false;
        });
    }

    public function test_heartbeat_not_configured_returns_false_without_request(): void
    {
        Http::fake();
        config(['moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);

        $this->assertFalse((new CloudClient)->heartbeat());
        Http::assertNothingSent();
    }

    public function test_heartbeat_swallows_failure(): void
    {
        $this->configureCloud();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertFalse((new CloudClient)->heartbeat()); // 不抛
    }

    public function test_heartbeat_non_ok_response_is_false(): void
    {
        $this->configureCloud();
        Http::fake(['cloud.test/api/v1/heartbeat' => Http::response(['ok' => false, 'error' => 'token 无此权限'], 403)]);

        $this->assertFalse((new CloudClient)->heartbeat());
    }

    // ---- 待办读写(供 moo:cloud:mcp 的 list_open_todos / get_todo / update_todo_status)----

    public function test_fetch_todos_hits_list_with_token_limit_status(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/todos/list' => Http::response([
                'ok' => true, 'todos' => [['id' => '01ABC', 'title' => '页面崩了', 'status' => 'open']],
            ], 200),
        ]);

        $r = (new CloudClient)->fetchTodos(7, 'open');

        $this->assertTrue($r['ok']);
        $this->assertSame('页面崩了', $r['data']['todos'][0]['title']);

        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/todos/list')
                && str_contains($req->body(), '"token"')
                && ($req->data()['limit'] ?? null)  === 7
                && ($req->data()['status'] ?? null) === 'open';
        });
    }

    public function test_fetch_todo_hits_get_with_id(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/todos/get' => Http::response([
                'ok' => true, 'todo' => ['id' => '01ABC', 'markdown' => '# Bug:页面崩了'],
            ], 200),
        ]);

        $r = (new CloudClient)->fetchTodo('01ABC');

        $this->assertTrue($r['ok']);
        $this->assertSame('# Bug:页面崩了', $r['data']['todo']['markdown']);
        Http::assertSent(fn ($req) => ($req->data()['id'] ?? null) === '01ABC');
    }

    public function test_update_todo_status_hits_status_with_payload(): void
    {
        $this->configureCloud();
        Http::fake([
            'cloud.test/api/v1/todos/status' => Http::response([
                'ok' => true, 'todo' => ['id' => '01ABC', 'status' => 'done'],
            ], 200),
        ]);

        $r = (new CloudClient)->updateTodoStatus('01ABC', 'done', '已修复', 'claude');

        $this->assertTrue($r['ok']);
        Http::assertSent(function ($req) {
            return str_starts_with((string) $req->url(), 'https://cloud.test/api/v1/todos/status')
                && ($req->data()['id'] ?? null)     === '01ABC'
                && ($req->data()['status'] ?? null) === 'done'
                && ($req->data()['note'] ?? null)   === '已修复'
                && ($req->data()['by'] ?? null)     === 'claude';
        });
    }

    public function test_todo_methods_not_configured_return_error_without_request(): void
    {
        Http::fake();
        config(['moo-monitor.cloud.base_url' => '', 'moo-monitor.cloud.token' => '']);

        $client = new CloudClient;
        $this->assertFalse($client->fetchTodos()['ok']);
        $this->assertFalse($client->fetchTodo('01ABC')['ok']);
        $this->assertFalse($client->updateTodoStatus('01ABC', 'done')['ok']);
        Http::assertNothingSent();
    }

    public function test_interactive_reads_retry_once_on_transient_connection_failure(): void
    {
        $this->configureCloud();
        // 第一拍连接失败、第二拍成功:retry(2) 的真重试应吃下瞬时抖动。
        // 原 retry(1) = 总尝试 1 次 = 零重试(Laravel retry(N) 的 N 是总尝试数),
        // 与注释自述「重试 1 次」相悖 —— 首页面板/MCP 工具对一次抖动直接失败(2026-06-11 修)。
        Http::fakeSequence('cloud.test/*')
            ->pushFailedConnection()
            ->push(['ok' => true, 'todos' => []], 200);

        $r = (new CloudClient)->fetchTodos();

        $this->assertTrue($r['ok']);
    }
}
