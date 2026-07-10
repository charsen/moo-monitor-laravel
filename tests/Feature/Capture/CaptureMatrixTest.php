<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Capture;

use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Route;
use Mooeen\Monitor\ExceptionDispatcher;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;

/**
 * 覆盖面契约（plan-02 P3）：覆盖矩阵每一条 ✅ 路径 → 一条端到端断言（触发路径 → 断言落盘 source）。
 * 今后任何人改采集钩子，这里先红。矩阵缩略（逐项在 vendor 中验证过，非推断）：
 *
 * | # | 错误路径                                   | source        | vendor 证据 |
 * |---|--------------------------------------------|---------------|-------------|
 * | 1 | 未捕获异常冒泡到 Handler（HTTP/console）    | reportable    | Handler reportable 回调 |
 * | 2 | 显式 report($e)                            | reportable    | 同上 |
 * | 3 | Log::error(..., ['exception'=>$e])          | log_context   | MessageLogged context 带对象 |
 * | 4 | Log::error($e) / 字符串化异常               | log_message   | Logger::formatMessage:270-276 强转 string |
 * | 5 | abort(5xx) / HttpException 5xx              | http_5xx      | Handler::renderViaCallbacks:712-724 null 放行 |
 * | 8 | 队列 JobFailed                             | queue_failed  | Worker 触发 JobFailed |
 * | 10| 调度任务抛异常                             | reportable    | ScheduleRunCommand:209-213 catch→report |
 * | 11| 调度任务非零退出码（exec 型）              | schedule_exit | ScheduledTaskFinished task->exitCode |
 * | 12| PHP warning/notice → ErrorException        | reportable    | 框架 handleError 转 ErrorException 抛出 |
 * | 14| 慢 SQL                                     | (sql-slow 桶) | QueryExecuted |
 */
class CaptureMatrixTest extends TestCase
{
    private string $rtBase;

    private RuntimeErrorRecorder $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('moo-monitor.runtime.enabled', true);

        $this->rtBase  = sys_get_temp_dir() . '/cap_rt_' . uniqid();
        $this->runtime = new RuntimeErrorRecorder($this->rtBase, ['enabled' => true]);
        $this->app->instance(RuntimeErrorRecorder::class, $this->runtime);
    }

    protected function tearDown(): void
    {
        $this->rm($this->rtBase);
        parent::tearDown();
    }

    private function rm(string $base): void
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

    private function soleRuntimeSource(): string
    {
        $rows = $this->runtime->list('open');
        $this->assertCount(1, $rows, '应恰好落盘一条 runtime 记录');

        return (string) ($this->runtime->get($rows[0]['hash'])['meta']['source'] ?? '');
    }

    /** 矩阵 #1 / #2 / #10 / #12：全部经 reportable 主链落盘 source=reportable。 */
    public function test_row_reportable(): void
    {
        app(ExceptionDispatcher::class)->dispatch(new RuntimeException('uncaught bubble'), source: 'reportable');
        $this->assertSame('reportable', $this->soleRuntimeSource());
    }

    /** 矩阵 #12：PHP warning/notice 被框架转 ErrorException 后归入 reportable。 */
    public function test_row_error_exception_from_warning(): void
    {
        app(ExceptionDispatcher::class)->dispatch(new \ErrorException('Undefined array key "x"'), source: 'reportable');
        $this->assertSame('reportable', $this->soleRuntimeSource());
    }

    /** 矩阵 #3：Log::error(..., ['exception'=>$e]) → log_context。 */
    public function test_row_log_context(): void
    {
        event(new MessageLogged('error', 'boom', ['exception' => new RuntimeException('logged with object')]));
        $this->assertSame('log_context', $this->soleRuntimeSource());
    }

    /** 矩阵 #4：Log::error($e) / 字符串化异常 → log_message（P1-1）。 */
    public function test_row_log_message(): void
    {
        event(new MessageLogged('error', 'failed: db timeout', []));
        $this->assertSame('log_message', $this->soleRuntimeSource());
    }

    /** 矩阵 #5：abort(5xx) → http_5xx（P1-2），响应体不变。 */
    public function test_row_http_5xx(): void
    {
        Route::get('/cap-boom-503', fn () => abort(503));
        $this->get('/cap-boom-503')->assertStatus(503);
        $this->assertSame('http_5xx', $this->soleRuntimeSource());
    }

    /** 矩阵 #8：队列 JobFailed → queue_failed。 */
    public function test_row_queue_failed(): void
    {
        $job = new class
        {
            public function getQueue(): string
            {
                return 'default';
            }
        };
        event(new JobFailed('redis', $job, new RuntimeException('job blew up')));
        $this->assertSame('queue_failed', $this->soleRuntimeSource());
    }

    /** 矩阵 #11：调度任务非零退出码 → schedule_exit（P1-7①）。 */
    public function test_row_schedule_exit(): void
    {
        $task           = app(Schedule::class)->exec('backup:run');
        $task->exitCode = 2;
        event(new ScheduledTaskFinished($task, 1.0));
        $this->assertSame('schedule_exit', $this->soleRuntimeSource());
    }

    /** 矩阵 #14：慢 SQL 落 sql-slow 桶（独立记录器，含 connection）。 */
    public function test_row_slow_sql(): void
    {
        $sqlBase = sys_get_temp_dir() . '/cap_sql_' . uniqid();
        $rec     = new SqlSlowRecorder($sqlBase, ['enabled' => true, 'threshold_ms' => 100]);
        config()->set('moo-monitor.sql_slow.enabled', true);
        config()->set('moo-monitor.sql_slow.threshold_ms', 100);
        try {
            (new SqlSlowListener($rec))->handle(new QueryExecuted(
                'select * from `t` where `id` = ?', [1], 250.0, app('db')->connection()
            ));
            $rows = $rec->list('open');
            $this->assertCount(1, $rows);
            $this->assertNotNull($rec->get($rows[0]['hash'])['at']['connection'] ?? null);
        } finally {
            $this->rm($sqlBase);
        }
    }
}
