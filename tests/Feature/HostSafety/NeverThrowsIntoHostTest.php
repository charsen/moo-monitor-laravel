<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\HostSafety;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Mooeen\Monitor\Concerns\SafelyLogs;
use Mooeen\Monitor\ExceptionDispatcher;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;

/**
 * 核心不变量回归锁:采集链路绝不向宿主抛错。
 *
 * 审查发现:异常上报 / 慢 SQL 正是宿主日志后端(database / slack 等通道)最可能抖动的时刻;
 * 而采集链路的兜底 catch 里又有裸 Log::error/warning,日志写入自身抛错就会逃出 record()/dispatch()/
 * handle(),冒泡进宿主异常处理链(顶替 renderException → 白屏)或查询执行(把成功查询变成抛异常)。
 * 现在统一走 SafelyLogs::safeLog,日志写不出也静默吞掉。本测试用「任何写入都抛」的假日志后端锁死该保证。
 */
class NeverThrowsIntoHostTest extends TestCase
{
    /** 把容器里的 'log' 换成「任何方法调用都抛错」的假后端,模拟日志通道后端不可用。 */
    private function bindThrowingLogger(): void
    {
        $this->app->instance('log', new class
        {
            /** @param array<int,mixed> $args */
            public function __call(string $name, array $args): mixed
            {
                throw new RuntimeException('log backend down: ' . $name);
            }
        });
    }

    public function test_safe_log_swallows_a_throwing_log_backend(): void
    {
        $this->bindThrowingLogger();

        $sink = new class
        {
            use SafelyLogs;

            public function ping(): void
            {
                $this->safeLog('error', 'should not bubble', ['k' => 'v']);
            }
        };

        $sink->ping(); // 不抛即通过
        $this->assertTrue(true);
    }

    public function test_runtime_recorder_never_throws_when_log_backend_throws(): void
    {
        $this->bindThrowingLogger();

        // basePath 落在一个普通文件下 → open 目录建不出来 → 写盘失败 → logWriteFailure 走 safeLog。
        $file = tempnam(sys_get_temp_dir(), 'rt_safe_');
        $base = $file . '/nested';
        try {
            $r    = new RuntimeErrorRecorder($base, ['enabled' => true]);
            $hash = $r->record(new RuntimeException('boom'));
            $this->assertNull($hash); // 写失败返 null,且没因日志抛错而冒泡
        } finally {
            @unlink($file);
        }
    }

    public function test_sql_slow_recorder_never_throws_when_log_backend_throws(): void
    {
        $this->bindThrowingLogger();

        $file = tempnam(sys_get_temp_dir(), 'sq_safe_');
        $base = $file . '/nested';
        try {
            $r    = new SqlSlowRecorder($base, ['enabled' => true]);
            $hash = $r->record('select * from `t` where `id` = ?', 'select * from `t` where `id` = 1', 200.0, '/app/Foo.php', 42);
            $this->assertNull($hash);
        } finally {
            @unlink($file);
        }
    }

    public function test_dispatcher_never_throws_when_recorder_and_log_throw(): void
    {
        $this->bindThrowingLogger();

        // 让 record() 直接抛(模拟容器/落盘在异常上报时刻处于异常态):dispatch 必须静默吞掉。
        $this->app->instance(RuntimeErrorRecorder::class, new class(null, ['enabled' => true]) extends RuntimeErrorRecorder
        {
            public function record(\Throwable $e, ?Request $request = null, string $source = 'reportable', array $meta = []): ?string
            {
                throw new RuntimeException('recorder exploded');
            }
        });

        (new ExceptionDispatcher)->dispatch(new RuntimeException('origin')); // 不抛即通过
        $this->assertTrue(true);
    }

    public function test_sql_slow_listener_never_throws_when_recorder_and_log_throw(): void
    {
        $this->bindThrowingLogger();
        config()->set('moo-monitor.sql_slow.enabled', true);
        config()->set('moo-monitor.sql_slow.threshold_ms', 100);

        $recorder = new class(sys_get_temp_dir() . '/never_throw', ['enabled' => true]) extends SqlSlowRecorder
        {
            public function record(string $sqlRaw, string $sqlLast, float $tookMs, string $file, int $line, ?string $connection = null, ?Request $request = null): ?string
            {
                throw new RuntimeException('recorder exploded');
            }
        };

        $event = new QueryExecuted('select * from `t` where `id` = ?', [1], 250.0, app('db')->connection());
        (new SqlSlowListener($recorder))->handle($event); // 不抛即通过
        $this->assertTrue(true);
    }
}
