<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use RuntimeException;

/**
 * 调度任务非零退出码哨兵异常（矩阵 #11，P1-7① 已拍板采集）。
 *
 * exec 型调度任务（不抛异常、只以非零退出码表示失败）过去完全不可见 —— ScheduleRunCommand 只用
 * exitCode 判成败、不上报。这里监听 ScheduledTaskFinished / ScheduledBackgroundTaskFinished，
 * 命中非零退出码时合成本异常进 record 管道（source=schedule_exit）。
 *
 * 与 LoggedErrorMessage 同款：构造器直接写 protected $file / $line，复用整条 record() 管道。
 * 单独成类是为「可识别」：云端按异常类聚合，看到本类即知是调度退出码告警而非代码 bug。
 * message 里的退出码经 normalizeMessage 数字归一为 N，故同一 command 不同退出码聚合到同一 hash。
 */
class ScheduledTaskExit extends RuntimeException
{
    public function __construct(string $message, string $file, int $line)
    {
        parent::__construct($message);
        $this->file = $file;
        $this->line = $line;
    }
}
