<?php

declare(strict_types=1);

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Mooeen\MonitorLaravel\ExceptionDispatcher;
use Mooeen\MonitorLaravel\Recorder\SqlSlowListener;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('所有异常旁路在 dispatcher 解析失败时保留宿主行为，诊断日志不反馈采集', function () {
    $attempts = 0;
    app()->forgetInstance(ExceptionDispatcher::class);
    app()->bind(ExceptionDispatcher::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('token=must-not-be-logged');
    });
    $logger = new class
    {
        public array $warnings = [];

        public function log($level, $message, $context): void {}

        public function warning($message, $context): void
        {
            $this->warnings[] = [$message, $context];
            event(new MessageLogged('error', $message, $context + ['exception' => new RuntimeException('internal')]));
        }
    };
    app()->instance('log', $logger);
    $handler = app(ExceptionHandler::class);
    $handler->report(new RuntimeException('host failure'));
    $response = $handler->render(Request::create('/'), new HttpException(503));
    expect($response->getStatusCode())->toBe(503);

    event(new MessageLogged('error', 'host log', ['exception' => new RuntimeException('original')]));
    event(new MessageLogged('error', 'host plain log', []));
    $job = Mockery::mock(\Illuminate\Contracts\Queue\Job::class)->shouldIgnoreMissing();
    event(new JobFailed('sync', $job, new RuntimeException('job failure')));

    $task           = app(Schedule::class)->exec('host:failure');
    $task->exitCode = 2;
    event(new ScheduledTaskFinished($task, 0.1));
    event(new ScheduledBackgroundTaskFinished($task));
    $monitor           = app(Schedule::class)->command('moo:cloud:push');
    $monitor->exitCode = 1;
    event(new ScheduledTaskFailed($monitor, new Exception("Scheduled command [{$monitor->command}] failed with exit code [1].")));

    expect($attempts)->toBe(8)
        ->and($logger->warnings)->toHaveCount(8)
        ->and(json_encode($logger->warnings))->not->toContain('must-not-be-logged');
});

it('慢 SQL 解析失败的诊断查询不递归，恢复绑定后仍能采集', function () {
    $attempts = 0;
    $event    = new QueryExecuted('select 1', [], 200, app('db')->connection());
    app()->forgetInstance(SqlSlowListener::class);
    app()->bind(SqlSlowListener::class, function () use (&$attempts) {
        $attempts++;
        if ($attempts > 3) {
            throw new LogicException('bounded recursion');
        }
        throw new RuntimeException('resolution failed');
    });
    app()->instance('log', new class($event)
    {
        public function __construct(private QueryExecuted $event) {}

        public function warning($message, $context): void
        {
            event($this->event);
        }
    });
    event($event);
    event($event);
    expect($attempts)->toBe(2);

    $listener = Mockery::mock(SqlSlowListener::class);
    $listener->shouldReceive('handle')->once()->with($event);
    app()->instance(SqlSlowListener::class, $listener);
    event($event);
});

it('日志上下文读取失败和诊断日志后端失败均不向宿主抛出', function () {
    config(['moo-monitor.exception.log_context_levels' => [new stdClass]]);
    app()->instance('log', new class
    {
        public function warning($message, $context): void
        {
            throw new RuntimeException('logger unavailable');
        }
    });
    event(new MessageLogged('error', 'host message', []));
    expect(true)->toBeTrue();
});
