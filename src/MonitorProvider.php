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
    public const VERSION = '0.1.13';

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
        $this->publishesConfig();
        $this->listenSlowQueries();
        $this->hookExceptionReporting();
        $this->scheduleCloudPush();
    }

    private function publishesConfig(): void
    {
        $this->publishes([
            __DIR__ . '/../config/moo-monitor.php' => config_path('moo-monitor.php'),
        ], 'moo-monitor-config');
    }

    /** 慢 SQL 监听 — 必须同步（QueryExecuted 携带 PDO 引用，不能进 Queue Job）。 */
    private function listenSlowQueries(): void
    {
        Event::listen(
            \Illuminate\Database\Events\QueryExecuted::class,
            [SqlSlowListener::class, 'handle'],
        );
    }

    /** 异常上报的全部采集钩子：reportable 主链 + 五条旁路（http_5xx / log_context / log_message / queue_failed / schedule_exit）。 */
    private function hookExceptionReporting(): void
    {
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

        // 调度任务非零退出码（矩阵 #11，P1-7① 已拍板）：exec 型任务不抛异常、只以退出码表示失败，
        // 过去完全不可见。监听两个完成事件（后台任务经 schedule:finish 回填退出码后发 Background 版，
        // 两个都要接），退出码为非 0 整数时合成 ScheduledTaskExit 记一条。
        // Laravel 8 到此结束；Laravel 12 对前台非零退出还会紧接着合成普通 Exception，依次发
        // ScheduledTaskFailed + report。用同一 task 对象关联这段事件序列，抑制后续普通 Exception，
        // 避免同一次失败被 schedule_exit / reportable 双计。真实任务抛异常没有先发 Finished，不受影响。
        $handledScheduledExits = new \WeakMap;
        if ((bool) config('moo-monitor.exception.schedule_exit_hook', true)) {
            $onScheduledFinish = function ($event) use ($handledScheduledExits): void {
                $task = $event->task ?? null;
                if (! is_object($task)) {
                    return;
                }

                // repeating event 可能复用同一 task；每次 Finished 先清掉上轮标记。
                unset($handledScheduledExits[$task]);
                if ($this->recordScheduledExit($event)) {
                    $handledScheduledExits[$task] = true;
                }
            };
            Event::listen(\Illuminate\Console\Events\ScheduledTaskFinished::class, $onScheduledFinish);
            Event::listen(\Illuminate\Console\Events\ScheduledBackgroundTaskFinished::class, $onScheduledFinish);
        }
        // 防回环不受 schedule_exit_hook 开关控制：即使宿主关闭非零退出采集，Laravel 为本包
        // Cloud 命令非零退出合成的普通 Exception 仍必须跳过。真实 Error / RuntimeException 继续采集，
        // 否则会隐藏 CloudSync 自身代码缺陷。普通任务只有在前面已处理 Finished 时才去重。
        Event::listen(\Illuminate\Console\Events\ScheduledTaskFailed::class, function ($event) use ($handledScheduledExits): void {
            $task      = $event->task      ?? null;
            $exception = $event->exception ?? null;
            if (! is_object($task) || ! $exception instanceof Throwable) {
                return;
            }
            if (! $this->isFrameworkScheduledExitException($task, $exception)) {
                return;
            }
            $summary = (string) $this->safeJobValue(fn () => method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : '');
            if (! $this->isMonitorCloudCommand($task, $summary) && ! isset($handledScheduledExits[$task])) {
                return;
            }

            unset($handledScheduledExits[$task]);
            $this->app->make(ExceptionDispatcher::class)->suppress($exception);
        });
    }

    /**
     * 云端推送：cloud.enabled + cloud.schedule 同时为真时，自动挂每分钟调度（需宿主跑 schedule:run）。
     * 仅 console 注册（scheduler 只在 CLI 生效）；用 booted 确保 Schedule 已可解析。
     */
    private function scheduleCloudPush(): void
    {
        if ($this->app->runningInConsole()) {
            $this->app->booted(function () {
                $cfg = (array) config('moo-monitor.cloud', []);
                if (($cfg['enabled'] ?? false) && ($cfg['schedule'] ?? true)) {
                    $parameters  = [];
                    $environment = StorageScope::selectedCommandEnvironment();
                    if ($environment !== null) {
                        $parameters['--env'] = $environment;
                    }

                    $event = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                        ->command('moo:cloud:push', $parameters)
                        ->everyMinute()
                        // 显式 10 分钟锁过期：防一次卡死的 run 用默认 24h 锁把后续推送全堵死。
                        ->withoutOverlapping(10);

                    // Laravel 的后台任务由独立 schedule:finish 进程释放 withoutOverlapping 锁，
                    // 但该进程不会继承父级 schedule:run/work 的 --env。scoped mutex 因而无法命中，
                    // 只能等 10 分钟过期。带环境选择器时留在原进程前台执行，确保释放同一把锁；
                    // 普通单环境部署继续沿用后台执行行为。
                    if ($environment === null) {
                        $event->runInBackground();
                    }
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
     * 调度完成事件 → 非零退出码合成 schedule_exit 记录。exitCode 非 int（未知）或 0（成功）不采。
     * 返回 true 表示这是一个已处理的非零退出（含明确跳过的本包 cloud 命令），供 Laravel 12
     * 后续 ScheduledTaskFailed 事件关联并抑制框架合成的重复 Exception。
     *
     * @param \Illuminate\Console\Events\ScheduledTaskFinished|\Illuminate\Console\Events\ScheduledBackgroundTaskFinished $event
     */
    private function recordScheduledExit(object $event): bool
    {
        $task = $event->task ?? null;
        if ($task === null) {
            return false;
        }
        $code = $task->exitCode ?? null;
        // null = 退出码未知（视为不确定，不采）；0 = 成功。仅非零整数才合成记录。
        if (! is_int($code) || $code === 0) {
            return false;
        }

        $summary = (string) $this->safeJobValue(fn () => method_exists($task, 'getSummaryForDisplay') ? $task->getSummaryForDisplay() : '');
        $summary = $summary !== '' ? $summary : 'unknown';
        // Cloud push 自身失败若进入 runtime 缓冲，会在下一轮再次推回同一个 Cloud，形成
        // 「推送失败 → 采集推送命令失败 → 待推数据增加」的自反馈。Cloud heartbeat 已专门负责
        // 监测推送中断，因此本包所有 moo:cloud:* 调度非零退出及框架为它合成的普通异常都跳过。
        // 命令内部真正抛出的 Error / RuntimeException 仍走 reportable，保留具体根因。
        if ($this->isMonitorCloudCommand($task, $summary)) {
            return true;
        }
        // 退出码进 message：normalizeMessage 把数字归一为 N，同一 command 不同退出码聚合到同一 hash。
        $message = '调度任务退出码非零：' . mb_substr($summary, 0, 300) . ' (exit ' . $code . ')';

        $meta = ['command' => mb_substr($summary, 0, 500), 'exit_code' => $code];
        if (isset($event->runtime)) {
            $meta['runtime'] = round((float) $event->runtime, 2);
        }

        // file 用合成标记（非真实源文件）：source_snippet 自然取空、所有 schedule_exit 共桶按 command 聚合。
        $synthetic = new \Mooeen\Monitor\ScheduledTaskExit($message, 'moo-monitor/schedule', 0);
        $this->app->make(ExceptionDispatcher::class)->dispatch($synthetic, source: 'schedule_exit', meta: $meta);

        return true;
    }

    /** Laravel 12 对前台非零退出码合成的普通 Exception；必须同时命中 task/command/code 才去重。 */
    private function isFrameworkScheduledExitException(object $task, Throwable $exception): bool
    {
        $command = $task->command  ?? null;
        $code    = $task->exitCode ?? null;
        if (get_class($exception) !== \Exception::class || ! is_string($command) || ! is_int($code) || $code === 0) {
            return false;
        }

        return $exception->getMessage() === "Scheduled command [{$command}] failed with exit code [{$code}].";
    }

    /** 识别直接或经 shell 包装调度的本包 Cloud 命令。 */
    private function isMonitorCloudCommand(object $task, string $summary): bool
    {
        $command = $task->command ?? '';
        foreach ([is_string($command) ? $command : '', $summary] as $value) {
            if (preg_match('/(?:^|[\\s\'\"])moo:cloud:[a-z0-9:_-]+(?:$|[\\s\'\"])/i', $value) === 1) {
                return true;
            }
        }

        return false;
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
