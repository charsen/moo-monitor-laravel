<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\ScheduledTaskExit;
use Mooeen\Monitor\Tests\TestCase;

/**
 * P1-7① 回归锁：调度任务非零退出码采集（矩阵 #11）。
 *
 * exec 型任务不抛异常、只以退出码表示失败。监听 ScheduledTaskFinished / ScheduledBackgroundTaskFinished，
 * 非零整数退出码合成 ScheduledTaskExit（source=schedule_exit）；null/0 不采；同一 command 不同退出码聚合同 hash。
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
