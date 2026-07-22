<?php

declare(strict_types=1);

namespace Mooeen\Monitor;

use Illuminate\Http\Request;
use Mooeen\Monitor\Concerns\SafelyLogs;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Throwable;
use WeakMap;

/**
 * 统一异常分发（仅落盘）
 *
 * MonitorProvider 在 `exception.auto_hook`（默认开）时自动挂到 host 的 reportable 链；
 * 宿主也可在 `bootstrap/app.php` 手动接入（两者并存不会双计，见 dispatch 的 WeakMap 防重）。
 * 异常落到 runtime channel:
 *   - runtime → RuntimeErrorRecorder 落盘 storage/moo-monitor/runtimes/<hash>.yaml，随后由 moo:cloud:push 推送云端
 *
 * 邮件 / 钉钉通知全部由 moo-scaffold-cloud 在云端 intake 时按项目规则触发，
 * host 不再本地发送（去重 / VIP / 多渠道统一在云端处理）。过滤（dontReport / 类列表）仍下沉 host 层。
 */
class ExceptionDispatcher
{
    use SafelyLogs;

    /**
     * 本进程内已分发过的异常对象。auto_hook 与宿主手动 reportable 接入并存时，同一异常对象
     * 会进来两次 —— 用 WeakMap（而非 spl_object_id 数组）防双计：对象被 GC 后条目自动消失，
     * 长生命周期 worker(Octane/队列)下既不泄漏、也无 object id 复用导致的误判。
     */
    private WeakMap $dispatched;

    /**
     * 框架已被更精确入口覆盖、后续 reportable / log_context 不应再重复采集的异常对象。
     * 当前用于 Laravel 12 的调度非零退出：Finished 已合成 ScheduledTaskExit 后，框架又会
     * 合成一个普通 Exception 并 report；两者描述同一次失败，只保留前者。
     */
    private WeakMap $suppressed;

    public function __construct()
    {
        $this->dispatched = new WeakMap;
        $this->suppressed = new WeakMap;
    }

    /** 标记一个已由更精确入口处理的异常对象，后续所有采集入口均忽略。 */
    public function suppress(Throwable $e): void
    {
        $this->suppressed[$e] = true;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function dispatch(Throwable $e, ?Request $request = null, string $source = 'reportable', array $meta = []): void
    {
        if (isset($this->suppressed[$e])) {
            return;
        }

        // 防双计的 WeakMap 标记放在 try 之前：它必须先生效（否则抛错-重试会双计），isset/赋值本身不抛。
        // 首见即标记；重复且来源优先级更高才更新 source（tagSource 升级路径）。
        $repeat  = isset($this->dispatched[$e]);
        $upgrade = $repeat && $this->sourcePriority($source) > $this->sourcePriority((string) ($this->dispatched[$e]['source'] ?? 'reportable'));
        if (! $repeat || $upgrade) {
            $this->dispatched[$e] = ['source' => $source];
        }

        // 单一总闸（P4）：tagSource 升级路径与 record 落盘路径同处一个 try —— 采集链路对宿主的硬保证是
        // 「永不抛」。config()/request()/落盘 都要从容器解析，而异常上报正发生在容器/请求状态不一定健康的
        // 时刻，任一处抛错都会冒泡进宿主 reportable 链、顶替掉 renderException。safeLog 自身也绝不抛。
        try {
            if ($repeat) {
                // 同一异常对象再次进来（auto_hook + 手动接入并存，或多入口）：只升级 source，不重复计数。
                if ($upgrade) {
                    app(RuntimeErrorRecorder::class)->tagSource($e, $source, $meta);
                }

                return;
            }

            if (! (bool) config('moo-monitor.runtime.enabled', true)) {
                return;
            }
            if ((bool) config('moo-monitor.exception.cli_experiment_skip', true) && $this->isCliExperiment($e)) {
                return;
            }
            if ((bool) config('moo-monitor.exception.console_input_skip', true) && $this->isConsoleInputError($e)) {
                return;
            }

            // 仅落盘（邮件/钉钉/企微通知由云端 intake 触发）；request 传显式值或 null，由 record 做 console 感知解析。
            // 落盘失败的诊断由 RuntimeErrorRecorder 自己记（它才分得清「写失败」与「按规则跳过」）。
            app(RuntimeErrorRecorder::class)->record($e, $request, $source, $meta);
        } catch (Throwable $self) {
            $this->safeLog('error', 'exception-dispatcher failed: ' . $self->getMessage(), [
                'origin_class' => get_class($e),
                'origin_at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    private function sourcePriority(string $source): int
    {
        // source 优先级真源在 RuntimeErrorRecorder::SOURCE_PRIORITY（P2-2 收口）；此处复用同一语义，不再各持一份。
        return RuntimeErrorRecorder::sourceRank($source);
    }

    /**
     * 终端命令用法错（命令不存在 / 参数缺失 / 选项非法 等）—— Symfony Console 的所有输入异常
     * 都实现 \Symfony\Component\Console\Exception\ExceptionInterface。这类是「敲错命令」，不是
     * 应用 runtime 错误，不该落 runtimes。仅 console 上下文判定，HTTP 不受影响。
     */
    private function isConsoleInputError(Throwable $e): bool
    {
        if (! app()->runningInConsole()) {
            return false;
        }

        return $e instanceof \Symfony\Component\Console\Exception\ExceptionInterface;
    }

    private function isCliExperiment(Throwable $e): bool
    {
        if (str_contains($e->getFile(), 'Command line code')) {
            return true;
        }
        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && str_contains($frame['file'], 'Command line code')) {
                return true;
            }
        }

        return false;
    }
}
