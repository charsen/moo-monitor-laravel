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

    public function __construct()
    {
        $this->dispatched = new WeakMap;
    }

    /**
     * @param array<string,mixed> $meta
     */
    public function dispatch(Throwable $e, ?Request $request = null, string $source = 'reportable', array $meta = []): void
    {
        // 防双计的 WeakMap 标记放在 try 之前：它必须先生效（否则抛错-重试会双计），
        // 且 isset/赋值本身不会抛。其余主体全部进 try —— request()/config()/跳过判断都要从容器
        // 解析服务，而异常上报正发生在容器/请求状态不一定健康的时刻，任一处抛错都会冒泡进宿主
        // 的 reportable 链、顶替掉 renderException(MonitorProvider 把 dispatch 无保护地挂了上去)。
        if (isset($this->dispatched[$e])) {
            $previous = (string) ($this->dispatched[$e]['source'] ?? 'reportable');
            if ($this->sourcePriority($source) > $this->sourcePriority($previous)) {
                $this->dispatched[$e] = ['source' => $source];
                $this->tagRuntimeSource($e, $source, $meta);
            }

            return;
        }
        $this->dispatched[$e] = ['source' => $source];

        try {
            $request ??= function_exists('request') ? request() : null;

            if (! (bool) config('moo-monitor.runtime.enabled', true)) {
                return;
            }

            if ((bool) config('moo-monitor.exception.cli_experiment_skip', true) && $this->isCliExperiment($e)) {
                return;
            }

            if ((bool) config('moo-monitor.exception.console_input_skip', true) && $this->isConsoleInputError($e)) {
                return;
            }

            // 仅落盘；邮件/钉钉/企微通知由云端在 intake 时触发。
            $this->dispatchRuntime($e, $request, $source, $meta);
        } catch (Throwable $self) {
            // 兜底：dispatch 对宿主的硬保证是「永不抛」。safeLog 自身也绝不抛（日志后端可能正不可用）。
            $this->safeLog('error', 'exception-dispatcher failed: ' . $self->getMessage(), [
                'origin_class' => get_class($e),
                'origin_at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function tagRuntimeSource(Throwable $e, string $source, array $meta): void
    {
        try {
            app(RuntimeErrorRecorder::class)->tagSource($e, $source, $meta);
        } catch (Throwable $self) {
            $this->safeLog('error', 'exception-dispatcher runtime source tag failed: ' . $self->getMessage(), [
                'origin_class' => get_class($e),
                'origin_at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    private function sourcePriority(string $source): int
    {
        return match ($source) {
            'queue_failed'  => 30,
            'schedule_exit' => 28,
            'http_5xx'      => 25,
            'log_context'   => 20,
            'log_message'   => 15,
            'reportable'    => 10,
            default         => 0,
        };
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function dispatchRuntime(Throwable $e, ?Request $request, string $source, array $meta): void
    {
        try {
            // 落盘失败的诊断由 RuntimeErrorRecorder 自己记（它才分得清「写失败」与「按规则跳过」）。
            app(RuntimeErrorRecorder::class)->record($e, $request, $source, $meta);
        } catch (Throwable $self) {
            $this->safeLog('error', 'exception-dispatcher runtime channel failed: ' . $self->getMessage(), [
                'origin_class' => get_class($e),
                'origin_at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
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
