<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use Illuminate\Contracts\Debug\ExceptionHandler;
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
                        $this->app->make(ExceptionDispatcher::class)->dispatch($e);
                    });
                }
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
}
