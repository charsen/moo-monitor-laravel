<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use Mooeen\Monitor\ExceptionDispatcher;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\ScheduledTaskExit;
use Mooeen\Monitor\Tests\TestCase;

/**
 * P1-7① 回归锁：调度任务非零退出码采集（矩阵 #11）。
 *
 * exec 型任务不抛异常、只以退出码表示失败。监听 ScheduledTaskFinished / ScheduledBackgroundTaskFinished，
 * 非零整数退出码合成 ScheduledTaskExit（source=schedule_exit）；Laravel 12 后续合成的普通 Exception 去重；
 * null/0 不采；同一 command 不同退出码聚合同 hash；moo:cloud:* 不采，避免监控链路自反馈。
 */
class ScheduleExitHookTest extends TestCase
{
    private function makeTask(string $command, ?int $exitCode)
    {
        $task           = app(Schedule::class)->exec($command);
        $task->exitCode = $exitCode;

        return $task;
    }

    public function test_nonzero_exit_records_schedule_exit_with_meta(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new ScheduledTaskFinished($this->makeTask('backup:run', 2), 1.5));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(ScheduledTaskExit::class),
            Mockery::any(),
            'schedule_exit',
            Mockery::on(fn (array $m) => ($m['exit_code'] ?? null) === 2
                && ($m['runtime'] ?? null)                         === 1.5
                && str_contains((string) ($m['command'] ?? ''), 'backup:run')),
        );
    }

    public function test_laravel_12_finished_failed_report_sequence_records_once(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $task      = $this->makeTask('backup:run', 2);
        $framework = new \Exception("Scheduled command [{$task->command}] failed with exit code [{$task->exitCode}].");

        event(new ScheduledTaskFinished($task, 1.5));
        event(new ScheduledTaskFailed($task, $framework));
        app(ExceptionDispatcher::class)->dispatch($framework, source: 'reportable');

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(ScheduledTaskExit::class),
            Mockery::any(),
            'schedule_exit',
            Mockery::type('array'),
        );
    }

    public function test_real_task_exception_without_finished_event_is_not_suppressed(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $task = $this->makeTask('backup:run', 1);
        $real = new \RuntimeException('backup callback crashed');

        event(new ScheduledTaskFailed($task, $real));
        app(ExceptionDispatcher::class)->dispatch($real, source: 'reportable');

        $spy->shouldHaveReceived('record')->once()->with(
            $real,
            Mockery::any(),
            'reportable',
            [],
        );
    }

    public function test_monitor_cloud_command_failure_is_not_recorded_or_reintroduced(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $task           = app(Schedule::class)->command('moo:cloud:push --type=runtimes')->runInBackground();
        $task->exitCode = 1;
        $framework      = new \Exception("Scheduled command [{$task->command}] failed with exit code [{$task->exitCode}].");

        event(new ScheduledTaskFinished($task, 2.0));
        event(new ScheduledTaskFailed($task, $framework));
        app(ExceptionDispatcher::class)->dispatch($framework, source: 'reportable');

        $spy->shouldNotHaveReceived('record');
    }

    public function test_monitor_cloud_command_real_exception_remains_visible(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $task = app(Schedule::class)->command('moo:cloud:push')->runInBackground();
        $real = new \Error('Call to undefined method CloudSync::readAckState()');

        event(new ScheduledTaskFailed($task, $real));
        app(ExceptionDispatcher::class)->dispatch($real, source: 'reportable');

        $spy->shouldHaveReceived('record')->once()->with(
            $real,
            Mockery::any(),
            'reportable',
            [],
        );
    }

    public function test_zero_exit_is_not_recorded(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new ScheduledTaskFinished($this->makeTask('ok:task', 0), 0.5));

        $spy->shouldNotHaveReceived('record');
    }

    public function test_null_exit_code_is_not_recorded(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new ScheduledTaskFinished($this->makeTask('unknown:task', null), 0.5));

        $spy->shouldNotHaveReceived('record');
    }

    public function test_background_finished_event_records_without_runtime(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new ScheduledBackgroundTaskFinished($this->makeTask('bg:task', 3)));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(ScheduledTaskExit::class),
            Mockery::any(),
            'schedule_exit',
            Mockery::on(fn (array $m) => ($m['exit_code'] ?? null) === 3 && ! array_key_exists('runtime', $m)),
        );
    }

    public function test_same_command_different_exit_codes_aggregate_to_one_hash(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $base = sys_get_temp_dir() . '/rt_sched_' . uniqid();
        $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
        $this->app->instance(RuntimeErrorRecorder::class, $rec);

        try {
            $task = $this->makeTask('report:daily', 2);
            event(new ScheduledTaskFinished($task, 1.0));
            $task->exitCode = 7;                       // 同 command，不同退出码
            event(new ScheduledTaskFinished($task, 2.0));

            $rows = $rec->list('open');
            expect($rows)->toHaveCount(1);             // 聚合到同一 hash
            expect($rows[0]['count'])->toBe(2);

            $data = $rec->get($rows[0]['hash']);
            expect($data['exception']['class'])->toBe(ScheduledTaskExit::class);
            expect($data['meta']['source'])->toBe('schedule_exit');
        } finally {
            foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                @rmdir($d);
            }
            @unlink($base . '/.gitignore');
            @rmdir($base);
        }
    }
}
