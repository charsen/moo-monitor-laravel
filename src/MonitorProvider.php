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
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * moo-monitor-laravel:Laravel 运行时异常 + 慢 SQL 监控，推送到 moo-scaffold-cloud。
 *
 * headless 包：不注册任何路由 / 视图，查看与处置统一走云端；
 * 本地只负责「采集 → 缓冲（storage/moo-monitor）→ 推送（moo:cloud:push）」。
 */
class MonitorProvider extends ServiceProvider
{
    public const VERSION = '0.1.9';

    public static function version(): string
    {
        try {
            if (class_exists(\Composer\InstalledVersions::class)) {
                $version = \Composer\InstalledVersions::getPrettyVersion('charsen/moo-monitor-laravel');
                if (is_string($version) && $version !== '') {
                    return ltrim($version, 'v');
                }
            }
        } catch (Throwable) {
            // Fallback below.
        }

        return self::VERSION;
    }

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

        // HttpException 5xx（矩阵 #5）：abort(500/502/503) 与第三方包抛的 5xx 全在框架 internalDontReport
        // 名单里，reportable 主链不可见（shouldntReport 挡在回调之前）。这里挂 renderable 观察者补采 ——
        // renderable 不受异常自带 report() 短路影响，返回 null 即放行框架默认渲染，宿主对外响应分毫不变
        // （vendor Handler::renderViaCallbacks:712-724）。独立开关，不随 auto_hook（与旁路钩子开关口径一致）。
        if ((bool) config('moo-monitor.exception.http_5xx_hook', true)) {
            $this->callAfterResolving(ExceptionHandler::class, function ($handler): void {
                if (method_exists($handler, 'renderable')) {
                    $handler->renderable(function (HttpException $e, $request) {
                        if ($e->getStatusCode() >= 500) {
                            $this->app->make(ExceptionDispatcher::class)->dispatch($e, source: 'http_5xx');
                        }

                        return null; // 关键：返回 null 放行后续渲染，绝不改变宿主对外响应
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
                if (! in_array($event->level, $this->logHookLevels(), true)) {
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

        // 字符串化异常进日志（矩阵 #4）：Log::error($e) / Log::error('失败: '.$e->getMessage()) 里
        // Throwable 在事件之前已被强转 string（Logger::formatMessage），context 无 exception 对象，
        // 上面的 log_context 钩子全漏。这里按调用点合成一条 LoggedErrorMessage 进同一 record 管道。
        if ((bool) config('moo-monitor.exception.log_message_hook', true)) {
            Event::listen(MessageLogged::class, function (MessageLogged $event): void {
                // 防回环（硬约束第六条）：本包 safeLog / logWriteFailure 也写 error 日志，若被本钩子
                // 当「字符串化异常」采集会「写盘失败 → error 日志 → 采集 → 又写盘失败」死循环。双保险：
                // ① static 重入闸（进入置 true，finally 复位）；② safeLog 打 moo_monitor_internal 标记，见标记即跳过。
                static $recording = false;
                if ($recording || ($event->context['moo_monitor_internal'] ?? false) === true) {
                    return;
                }
                if (! in_array($event->level, $this->logHookLevels(), true)) {
                    return;
                }
                // 已带真异常对象的走上面 log_context 钩子（信息量更高），这里只补「无异常对象」的字符串化形态。
                if (($event->context['exception'] ?? null) instanceof Throwable) {
                    return;
                }
                $message = trim((string) $event->message);
                if ($message === '') {
                    return;
                }

                $recording = true;
                try {
                    [$file, $line] = $this->logCallSite();
                    $synthetic     = new \Mooeen\Monitor\LoggedErrorMessage(mb_substr($message, 0, 1024), $file, $line);
                    $this->app->make(ExceptionDispatcher::class)->dispatch($synthetic, source: 'log_message', meta: [
                        'log_level'   => $event->level,
                        'log_message' => mb_substr($message, 0, 500),
                    ]);
                } finally {
                    $recording = false;
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

    /**
     * 触发日志采集钩子（log_context / log_message）的级别白名单。默认 error 及以上；
     * 想兜 warning 级的宿主改 exception.log_context_levels 配置（P1-7③：只配置化、默认不放宽）。
     *
     * @return array<int,string>
     */
    private function logHookLevels(): array
    {
        $levels = (array) config('moo-monitor.exception.log_context_levels', ['error', 'critical', 'alert', 'emergency']);

        return array_values(array_filter(array_map('strval', $levels)));
    }

    /**
     * 定位「字符串化异常」的日志调用点：debug_backtrace 第一个应用帧即 Log::error() 的调用处。
     * 只看 file path、不看 class —— 与 SqlSlowListener::firstAppFrame() 同款判定（frame.file 是
     * 调用方文件、class/function 是 callee，看 class 会把 facade 调用误判）。跳过 vendor 与本包
     * 自身 src（含本 Provider 的监听闭包帧）；path repo + symlink 下路径不含 /vendor/，故叠子串兜底。
     *
     * @return array{0:string,1:int} [file, line]；找不到应用帧回退空 file / 0 line。
     */
    private function logCallSite(): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            if (! isset($frame['file'])) {
                continue;
            }
            $file = (string) $frame['file'];
            if ($file === '' || str_contains($file, '/vendor/') || str_contains($file, 'moo-monitor-laravel/src/')) {
                continue;
            }

            return [$file, (int) ($frame['line'] ?? 0)];
        }

        return ['', 0];
    }
}
