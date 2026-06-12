<?php

declare(strict_types=1);

namespace Mooeen\Monitor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Throwable;
use WeakMap;

/**
 * 统一异常分发(仅落盘)
 *
 * MonitorProvider 在 `exception.auto_hook`(默认开)时自动挂到 host 的 reportable 链;
 * 宿主也可在 `bootstrap/app.php` 手动接入(两者并存不会双计,见 dispatch 的 WeakMap 防重)。
 * 异常落到 runtime channel:
 *   - runtime → RuntimeErrorRecorder 落盘 storage/moo-monitor/runtimes/<hash>.yaml,随后由 moo:cloud:push 推送云端
 *
 * 邮件 / 钉钉通知全部由 moo-scaffold-cloud 在云端 intake 时按项目规则触发,
 * host 不再本地发送(去重 / VIP / 多渠道统一在云端处理)。过滤(dontReport / 类列表)仍下沉 host 层。
 */
class ExceptionDispatcher
{
    /**
     * 本进程内已分发过的异常对象。auto_hook 与宿主手动 reportable 接入并存时,同一异常对象
     * 会进来两次 —— 用 WeakMap(而非 spl_object_id 数组)防双计:对象被 GC 后条目自动消失,
     * 长生命周期 worker(Octane/队列)下既不泄漏、也无 object id 复用导致的误判。
     */
    private WeakMap $dispatched;

    public function __construct()
    {
        $this->dispatched = new WeakMap;
    }

    public function dispatch(Throwable $e, ?Request $request = null): void
    {
        if (isset($this->dispatched[$e])) {
            return;
        }
        $this->dispatched[$e] = true;

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

        // 仅落盘;邮件/钉钉/企微通知由云端在 intake 时触发。
        $this->dispatchRuntime($e, $request);
    }

    private function dispatchRuntime(Throwable $e, ?Request $request): void
    {
        try {
            // 落盘失败的诊断由 RuntimeErrorRecorder 自己记(它才分得清「写失败」与「按规则跳过」)。
            app(RuntimeErrorRecorder::class)->record($e, $request);
        } catch (Throwable $self) {
            Log::error('exception-dispatcher runtime channel failed: ' . $self->getMessage(), [
                'origin_class' => get_class($e),
                'origin_at'    => $e->getFile() . ':' . $e->getLine(),
            ]);
        }
    }

    /**
     * 终端命令用法错(命令不存在 / 参数缺失 / 选项非法 等)—— Symfony Console 的所有输入异常
     * 都实现 \Symfony\Component\Console\Exception\ExceptionInterface。这类是"敲错命令",不是
     * 应用 runtime 错误,不该落 runtimes。仅 console 上下文判定,HTTP 不受影响。
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
