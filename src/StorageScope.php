<?php declare(strict_types=1);

namespace Mooeen\Monitor;

use Throwable;

/**
 * 为同一 Laravel host 里通过 `artisan --env=XXX` 选择的多个项目隔离本地监控状态。
 *
 * `auto` 只在当前 Artisan environment 与 config('app.env') 不一致时启用，
 * 因而普通单环境部署继续使用历史路径；显式字符串可用于宿主自行指定 scope，
 * false / off / none 则关闭自动隔离。
 */
final class StorageScope
{
    /** 当前 scope；null 表示沿用旧的未加后缀路径。 */
    public static function current(): ?string
    {
        $configured = function_exists('config') ? config('moo-monitor.storage_scope', 'auto') : 'auto';

        if ($configured === false || $configured === null) {
            return null;
        }

        $value = strtolower(trim((string) $configured));
        if ($value === '' || in_array($value, ['false', 'off', 'none', 'disabled', '0'], true)) {
            return null;
        }

        if ($value === 'auto') {
            $selectedEnvironment   = self::selectedCommandEnvironment();
            $runtimeEnvironment    = self::runtimeEnvironment();
            $configuredEnvironment = function_exists('config') ? trim((string) config('app.env', '')) : '';

            // 仅环境值不一致不足以证明宿主在做多项目切换：Testbench、队列测试器或宿主自定义
            // bootstrap 也可能覆盖 app['env']。auto 必须同时看到真实 `--env=XXX` 选择器。
            if ($selectedEnvironment === null || $runtimeEnvironment === null || $configuredEnvironment === '') {
                return null;
            }
            if (strcasecmp($runtimeEnvironment, $configuredEnvironment) === 0) {
                return null;
            }

            return self::normalize($selectedEnvironment);
        }

        return self::normalize($value);
    }

    /** 给目录路径追加 `--{scope}`；无 scope 时原样返回。 */
    public static function scopePath(string $path): string
    {
        $scope = self::current();
        if ($scope === null || $path === '') {
            return $path;
        }

        $trimmed = rtrim($path, '/');
        if ($trimmed === '' || str_ends_with($trimmed, '--' . $scope)) {
            return $path;
        }

        return $trimmed . '--' . $scope;
    }

    /** 给文件名（保留扩展名）追加 `--{scope}`；无 scope 时原样返回。 */
    public static function scopeFile(string $path): string
    {
        $scope = self::current();
        if ($scope === null || $path === '') {
            return $path;
        }

        $directory = dirname($path);
        $filename  = basename($path);
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $stem      = $extension === '' ? $filename : substr($filename, 0, -strlen($extension) - 1);
        if (str_ends_with($stem, '--' . $scope)) {
            return $path;
        }

        $scoped = $stem . '--' . $scope . ($extension !== '' ? '.' . $extension : '');

        return ($directory === '.' ? '' : rtrim($directory, '/') . '/') . $scoped;
    }

    /** 给 cache key 追加 scope，避免不同项目共享 open-count / overflow 计数。 */
    public static function cacheKey(string $key): string
    {
        $scope = self::current();

        return $scope === null ? $key : $key . ':' . $scope;
    }

    private static function runtimeEnvironment(): ?string
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $environment = app()->environment();

            return is_string($environment) && trim($environment) !== '' ? trim($environment) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** 读取 Artisan 全局 `--env=XXX` / `--env XXX` 选择器；普通 console/web 运行返回 null。 */
    public static function selectedCommandEnvironment(): ?string
    {
        $args = $_SERVER['argv'] ?? null;
        if (! is_array($args)) {
            return null;
        }

        foreach ($args as $index => $arg) {
            if (! is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, '--env=')) {
                $value = substr($arg, 6);

                return $value !== '' ? $value : null;
            }
            if ($arg === '--env') {
                $value = $args[$index + 1] ?? null;

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? null : substr($value, 0, 64);
    }
}
