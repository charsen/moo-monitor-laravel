<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use RuntimeException;

/**
 * 「字符串化异常进日志」哨兵异常（矩阵 #4）。
 *
 * 业务代码里 `Log::error($e)`、`Log::error('失败: '.$e->getMessage())`、
 * `Log::error($e->getTraceAsString())` 极其常见 —— Logger::formatMessage 在 fireLogEvent 之前
 * 走 `default => (string) $message` 把 Throwable 强转 string（vendor Illuminate/Log/Logger.php:270-276），
 * 到 MessageLogged 时 message 已是纯字符串、context 里没有 exception 对象。原 log_context 钩子
 * 只认 `context['exception'] instanceof Throwable`，这类字符串化异常全漏。
 *
 * 本类把「一条 error 级日志消息 + 调用点 file/line」包成一个可进 record() 管道的合成异常：
 * 构造器里直接给 protected $file / $line 赋值（Exception 这两个属性 protected，子类可写）——
 * 这样整条 record() 管道（hash 按调用点聚合、source_snippet 取调用点源码、脱敏、daily_cap、
 * 桶管理）零改动复用。source 定为 log_message。
 */
class LoggedErrorMessage extends RuntimeException
{
    public function __construct(string $message, string $file, int $line)
    {
        parent::__construct($message);
        $this->file = $file;
        $this->line = $line;
    }
}
