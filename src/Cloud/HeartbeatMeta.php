<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Cloud;

use Mooeen\Monitor\MonitorProvider;

/**
 * 心跳 meta 组装：从 config 读采集 / 推送开关与环境信息，供 moo:cloud:push / moo:cloud:test 打心跳时携带。
 *
 * 从 CloudClient 挪出（P2-6）：CloudClient 的类 docblock 自声明「纯传输、不读 config」，原 heartbeatMeta()
 * 却读了 cloud / runtime / sql_slow 三段业务 config，名实不符。收口到本静态助手后，CloudClient 回归纯传输
 * （只接收调用方传入的 meta 原样发送），心跳请求体形状不变。
 */
final class HeartbeatMeta
{
    /**
     * @return array<string,mixed>
     */
    public static function collect(): array
    {
        $cloud   = (array) config('moo-monitor.cloud', []);
        $runtime = (array) config('moo-monitor.runtime', []);
        $slowSql = (array) config('moo-monitor.sql_slow', []);

        return [
            'sdk'              => 'moo-monitor-laravel',
            'sdk_version'      => MonitorProvider::version(),
            'php_version'      => PHP_VERSION,
            'laravel_version'  => function_exists('app') ? (string) app()->version() : '',
            'app_env'          => (string) config('app.env', 'unknown'),
            'app_name'         => (string) config('app.name', 'unknown'),
            'cloud_enabled'    => (bool) ($cloud['enabled'] ?? false),
            'runtime_enabled'  => (bool) ($runtime['enabled'] ?? true),
            'slow_sql_enabled' => (bool) ($slowSql['enabled'] ?? false),
            'push_runtimes'    => (bool) ($cloud['push']['runtimes'] ?? true),
            'push_slow_sql'    => (bool) ($cloud['push']['slow_sql'] ?? true),
            'schedule'         => (bool) ($cloud['schedule'] ?? true),
        ];
    }
}
