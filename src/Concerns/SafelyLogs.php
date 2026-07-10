<?php declare(strict_types=1);

namespace Mooeen\Monitor\Concerns;

use Throwable;

/**
 * 绝不抛错的日志写入。
 *
 * 采集链路（异常分发 ExceptionDispatcher / 慢 SQL 监听 SqlSlowListener / 两个 Recorder 落盘）挂在
 * 宿主的 reportable 链与 QueryExecuted 同步回调上 —— 这些回调被 Laravel 无 try/catch 直接调用
 * (vendor Foundation/Exceptions/Handler 的 reportable、Database/Connection::logQuery 均如此)。
 *
 * 关键风险：诊断日志本身也可能抛错 —— 宿主把日志栈配成 database / slack / 自定义 monolog handler
 * 时，后端不可用就会抛异常，而「异常上报 / 查询出错」正是日志后端最可能抖动的时刻。一旦 app('log')
 * 抛错，会冒泡进宿主异常处理链（顶替 renderException → 白屏）或宿主查询执行（把一次成功查询变成抛错）。
 *
 * 这里把日志写入兜成 best-effort：写得出就写，写不出静默吞掉，永不向调用方抛 —— 这是采集对宿主的唯一硬保证。
 */
trait SafelyLogs
{
    /**
     * @param 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency' $level
     * @param array<string,mixed>                                                      $context
     */
    protected function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            if (function_exists('app')) {
                // moo_monitor_internal 标记（防回环，硬约束第六条）：本包 safeLog 出的 error 级日志
                // 若被 log_message 采集钩子当成「字符串化异常」再采集，会形成
                // 「写盘失败 → error 日志 → 采集 → 又写盘失败」死循环。钩子见此标记即跳过。
                app('log')->{$level}($message, array_merge($context, ['moo_monitor_internal' => true]));
            }
        } catch (Throwable) {
            // 日志后端不可用也绝不向上抛 —— 见类注释，这是采集对宿主的核心保证。
        }
    }
}
