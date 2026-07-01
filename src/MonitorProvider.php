<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Mooeen\Monitor\Command\CloudMcpCommand;
use Mooeen\Monitor\Command\CloudPushCommand;
use Mooeen\Monitor\Command\CloudTestCommand;
use Mooeen\Monitor\Command\MigrateCommand;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Throwable;

/**
 * moo-monitor-laravel:Laravel 运行时异常 + 慢 SQL 监控，推送到 moo-scaffold-cloud。
 *
 * headless 包：不注册任何路由 / 视图，查看与处置统一走云端；
 * 本地只负责「采集 → 缓冲（storage/moo-monitor）→ 推送（moo:cloud:push）」。
 */
class MonitorProvider extends ServiceProvider
{
    public const VERSION = '0.1.8';

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/moo-monitor.php' => config_path('moo-monitor.php'),
        ], 'moo-monitor-config');

        // 慢 SQL 监听 — 必须同步（QueryExecuted 携带 PDO 引用，不能进 Queue Job）
        Event::listen(
            \Illuminate\Database\Events\QueryExecuted::class,
            [SqlSlowListener::class, 'handle'],
        );

        // 异常自动挂钩（exception.auto_hook，默认开）：把 ExceptionDispatcher 挂到 host 的
        // reportable 链，宿主零接入。Dispatcher 内部 WeakMap 防双计 —— 即使宿主 bootstrap/app.php
        // 还留着旧的手动接入，同一异常也只记一次。
        if ((bool) config('moo-monitor.exception.auto_hook', true)) {
            $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
                if (method_exists($handler, 'reportable')) {
                    $handler->reportable(function (Throwable $e): void {
                        $this->app->make(ExceptionDispatcher::class)->dispatch($e, source: 'reportable');
                    });
                }
            });
        }

        // 日志异常兜底：业务代码 / 队列 failed 回调常只做
        // Log::error('...', ['exception' => $e])，不会再 report($e)。这种异常过去只留在
        // laravel.log，云端 runtimes 无感知。复用同一个 ExceptionDispatcher，若同一异常对象
        // 已走过 reportable，WeakMap 会自动去重。
        if ((bool) config('moo-monitor.exception.log_context_hook', true)) {
            Event::listen(MessageLogged::class, function (MessageLogged $event): void {
                if (! in_array($event->level, ['error', 'critical', 'alert', 'emergency'], true)) {
                    return;
                }

                $exception = $event->context['exception'] ?? null;
                if ($exception instanceof Throwable) {
                    $this->app->make(ExceptionDispatcher::class)->dispatch($exception, source: 'log_context', meta: [
                        'log_level'   => $event->level,
                        'log_message' => mb_substr((string) $event->message, 0, 500),
                    ]);
                }
            });
        }

        // 队列失败事件是业务运行时错误的高价值入口。很多项目只依赖 Laravel 的 failed_jobs /
        // failed 回调，不会显式 report($e)，因此这里直接接 JobFailed，仍交给同一个 dispatcher 去重。
        if ((bool) config('moo-monitor.exception.queue_failed_hook', true)) {
            Event::listen(JobFailed::class, function (JobFailed $event): void {
                $job  = $event->job;
                $meta = [
                    'connection' => $event->connectionName,
                    'queue'      => $this->safeJobValue(fn () => $job?->getQueue()),
                    'job_name'   => $this->safeJobValue(fn () => method_exists($job, 'resolveName') ? $job->resolveName() : (method_exists($job, 'getName') ? $job->getName() : null)),
                    'attempts'   => $this->safeJobValue(fn () => method_exists($job, 'attempts') ? $job->attempts() : null),
                ];

                $this->app->make(ExceptionDispatcher::class)->dispatch($event->exception, source: 'queue_failed', meta: $meta);
            });
        }

        // 云端推送：cloud.enabled + cloud.schedule 同时为真时，自动挂每分钟调度（需宿主跑 schedule:run）。
        // 仅 console 注册（scheduler 只在 CLI 生效）；用 booted 确保 Schedule 已可解析。
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                $cfg = (array) config('moo-monitor.cloud', []);
                if (($cfg['enabled'] ?? false) && ($cfg['schedule'] ?? true)) {
                    $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                        ->command('moo:cloud:push')
                        ->everyMinute()
                        // 显式 10 分钟锁过期：防一次卡死的 run 用默认 24h 锁把后续推送全堵死。
                        ->withoutOverlapping(10)
                        ->runInBackground();
                }
            });
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/moo-monitor.php', 'moo-monitor');

        $this->app->singleton(RuntimeErrorRecorder::class, fn () => new RuntimeErrorRecorder);
        $this->app->singleton(ExceptionDispatcher::class, fn () => new ExceptionDispatcher);
        $this->app->singleton(SqlSlowRecorder::class, fn () => new SqlSlowRecorder);
        $this->app->singleton(SqlSlowListener::class, fn ($app) => new SqlSlowListener($app->make(SqlSlowRecorder::class)));

        if ($this->app->runningInConsole()) {
            $this->commands([
                CloudPushCommand::class,
                CloudMcpCommand::class,
                CloudTestCommand::class,
                MigrateCommand::class,
            ]);
        }
    }

    private function safeJobValue(callable $reader): mixed
    {
        try {
            return $reader();
        } catch (Throwable) {
            return null;
        }
    }
}
