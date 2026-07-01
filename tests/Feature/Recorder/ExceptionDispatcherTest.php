<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Mooeen\Monitor\ExceptionDispatcher;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;

/**
 * ExceptionDispatcher 单测(仅落盘)
 *
 * 邮件 / 钉钉通知由 moo-scaffold-cloud(在 intake 时按项目规则触发)处理,
 * dispatcher 只委托 RuntimeErrorRecorder 落盘,不再发任何 Http / Mail。
 * 另锁 WeakMap 防双计:auto_hook 与宿主手动接入并存时,同一异常对象只记一次。
 */
class ExceptionDispatcherTest extends TestCase
{
    public function test_dispatch_records_runtime_and_sends_nothing(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        Http::fake();
        Mail::fake();

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        app(ExceptionDispatcher::class)->dispatch(new RuntimeException('boom'));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(RuntimeException::class),
            Mockery::any(),
            'reportable',
            [],
        );
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_globally_disabled_short_circuits(): void
    {
        config()->set('moo-monitor.runtime.enabled', false);

        Http::fake();
        Mail::fake();

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        app(ExceptionDispatcher::class)->dispatch(new RuntimeException('off'));

        $spy->shouldNotHaveReceived('record');
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_same_exception_object_dispatched_twice_records_once(): void
    {
        // auto_hook + 宿主手动 reportable 并存时,同一异常对象会进 dispatch 两次 —— WeakMap 防双计。
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $e          = new RuntimeException('boom twice');
        $dispatcher = app(ExceptionDispatcher::class);
        $dispatcher->dispatch($e);
        $dispatcher->dispatch($e);

        $spy->shouldHaveReceived('record')->once();
    }

    public function test_queue_failed_event_records_runtime_with_source(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $job = new class
        {
            public function getQueue(): string
            {
                return 'default';
            }

            public function attempts(): int
            {
                return 3;
            }

            public function resolveName(): string
            {
                return 'App\\Jobs\\FooJob';
            }
        };

        event(new JobFailed('redis', $job, new RuntimeException('job failed event')));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(RuntimeException::class),
            Mockery::any(),
            'queue_failed',
            Mockery::on(fn (array $meta) => ($meta['connection'] ?? null) === 'redis'
                && ($meta['queue'] ?? null)                               === 'default'
                && ($meta['job_name'] ?? null)                            === 'App\\Jobs\\FooJob'
                && ($meta['attempts'] ?? null)                            === 3),
        );
    }

    public function test_error_log_with_exception_context_records_runtime(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('error', '[Job Failed] App\\Jobs\\FooJob: boom', [
            'exception' => new RuntimeException('job failed from log context'),
        ]));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(RuntimeException::class),
            Mockery::any(),
            'log_context',
            Mockery::on(fn (array $meta) => ($meta['log_level'] ?? null) === 'error'
                && str_contains((string) ($meta['log_message'] ?? ''), 'FooJob')),
        );
    }

    public function test_log_context_hook_reuses_dispatcher_dedupe(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $e = new RuntimeException('reported then logged');
        app(ExceptionDispatcher::class)->dispatch($e);
        event(new MessageLogged('error', 'same exception later logged', ['exception' => $e]));

        $spy->shouldHaveReceived('record')->once();
    }

    public function test_non_error_log_context_is_ignored(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('info', 'noise', ['exception' => new RuntimeException('ignored')]));

        $spy->shouldNotHaveReceived('record');
    }

    public function test_different_exception_objects_record_separately(): void
    {
        // 防重只认「同一对象」:两个不同异常对象(即便同 message)都要记 —— 聚合靠 recorder 的 hash。
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $dispatcher = app(ExceptionDispatcher::class);
        $dispatcher->dispatch(new RuntimeException('boom'));
        $dispatcher->dispatch(new RuntimeException('boom'));

        $spy->shouldHaveReceived('record')->twice();
    }
}
