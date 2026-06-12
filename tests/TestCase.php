<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests;

use Mooeen\Monitor\MonitorProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * 包独立测试基类:Testbench 启最小 Laravel app,零 env 即可全绿。
 * 落盘类测试各自传入临时目录($basePath 构造参数),不污染 testbench 的 storage。
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [MonitorProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }
}
