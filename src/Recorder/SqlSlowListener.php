<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Recorder;

use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Mooeen\Monitor\Concerns\SafelyLogs;
use Throwable;

/**
 * 慢 SQL 监听器（仅落盘）
 *
 * 由 MonitorProvider 在 boot 时 Event::listen(QueryExecuted::class) 注册。
 * 超 `moo-monitor.sql_slow.threshold_ms` 的查询由 SqlSlowRecorder 落盘 yaml（聚合 hash），
 * 随后经 moo:cloud:push 推送云端；慢查询通知由云端在 intake 时按项目规则触发。
 *
 * 必须同步处理 — `QueryExecuted` 携带 PDO 引用，不能丢进 Queue Job（会 `Serialization of PDO is not allowed`）。
 */
class SqlSlowListener
{
    use SafelyLogs;

    public function __construct(private SqlSlowRecorder $recorder) {}

    public function handle(QueryExecuted $event): void
    {
        try {
            $config = (array) config('moo-monitor.sql_slow', []);
            if (! ($config['enabled'] ?? false)) {
                return;
            }

            $threshold = (float) ($config['threshold_ms'] ?? 100);
            if ((float) $event->time < $threshold) {
                return;
            }

            $sqlRaw = (string) $event->sql;
            foreach ((array) ($config['skip_patterns'] ?? []) as $needle) {
                if ($needle !== '' && str_contains($sqlRaw, (string) $needle)) {
                    return;
                }
            }

            $sqlLast = $this->fillBindings($sqlRaw, (array) $event->bindings);
            $frame   = $this->firstAppFrame();

            // 仅落盘；慢查询通知（钉钉/企微）由云端在 intake 时触发。
            $this->recorder->record(
                sqlRaw: $sqlRaw,
                sqlLast: $sqlLast,
                tookMs: (float) $event->time,
                file: $frame['file'] ?? '',
                line: (int) ($frame['line'] ?? 0),
            );
        } catch (Throwable $e) {
            // listener 自身不能抛 — 业务请求不能因慢 SQL 上报失败而 500。
            // 用 safeLog：日志写入本身也可能抛（database/slack 通道后端不可用），否则会逃出 handle()
            // → 逃出 Connection::logQuery() → 把一次已成功的查询变成抛异常。
            $this->safeLog('warning', 'sql-slow-listener failed: ' . $e->getMessage());
        }
    }

    /**
     * 顺序替换 ? 为 binding 值，避开 vsprintf 把 SQL 中字面 % 当格式符解析（`DATE_FORMAT(x,'%Y-%m-%d')` 翻车）。
     * 字符串 / DateTime 加单引号，其他类型 toString。
     */
    private function fillBindings(string $sql, array $bindings): string
    {
        $parts = explode('?', $sql);
        if (count($parts) === 1) {
            return $sql;
        }

        $out = array_shift($parts);
        foreach ($parts as $i => $part) {
            $out .= array_key_exists($i, $bindings)
                ? $this->formatBinding($bindings[$i])
                : '?';
            $out .= $part;
        }

        return $out;
    }

    private function formatBinding(mixed $binding): string
    {
        if ($binding instanceof DateTimeInterface) {
            return "'" . $binding->format('Y-m-d H:i:s') . "'";
        }
        if (is_string($binding)) {
            return "'" . $binding . "'";
        }
        if (is_bool($binding)) {
            return $binding ? '1' : '0';
        }
        if ($binding === null) {
            return 'NULL';
        }

        return (string) $binding;
    }

    /**
     * 找第一个属于「业务代码」的 stack frame。
     *
     * debug_backtrace 的 frame.file 是 **调用方代码所在文件**,frame.class/function 是 callee
     * 归属 — 所以判 user code 只看 file path，不看 class(否则 `\DB::select` 这种 facade 调用
     * 的 user 帧会被 class=Illuminate\\Support\\Facades\\Facade 误跳)。
     *
     * vendor 路径判 `/vendor/` 在 composer path repo + symlink 模式下不可靠(本包自身
     * 路径是 `/path/to/moo-monitor-laravel/src/...`，根本不含 `/vendor/`)，所以叠加
     * `moo-monitor-laravel/src/` 子串兜底。
     */
    private function firstAppFrame(): array
    {
        // 限 30 帧：只为找「第一个应用代码栈帧」，深栈（Filament/Livewire）整条抓回来每条慢查询都白建大数组。
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);
        foreach ($frames as $f) {
            if (! isset($f['file'])) {
                continue;
            }
            $file = (string) $f['file'];
            if ($file === '' || str_contains($file, '/vendor/') || str_contains($file, 'moo-monitor-laravel/src/')) {
                continue;
            }

            return ['file' => $file, 'line' => $f['line'] ?? 0];
        }

        return ['file' => '', 'line' => 0];
    }
}
