<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Cloud;

use Illuminate\Console\Scheduling\Schedule;
use Mooeen\Monitor\MonitorProvider;
use Mooeen\Monitor\Tests\TestCase;

/**
 * 自动 Cloud push 必须继承父级 Artisan 的 --env，否则多 .env 项目会回落默认项目并串数据。
 */
class CloudScheduleTest extends TestCase
{
    private mixed $originalArgv;

    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalArgv        = $_SERVER['argv'] ?? null;
        $this->originalEnvironment = (string) app()->environment();

        config([
            'moo-monitor.cloud.enabled'  => true,
            'moo-monitor.cloud.schedule' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app()->instance('env', $this->originalEnvironment);
        if ($this->originalArgv === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $this->originalArgv;
        }

        parent::tearDown();
    }

    public function test_scheduled_push_inherits_selected_environment_and_uses_an_independent_mutex(): void
    {
        $schedule = app(Schedule::class);

        $projectA = $this->scheduleFor('PROJECT_A');
        $projectB = $this->scheduleFor('PROJECT_B');

        $this->assertMatchesRegularExpression('/--env=[\'\"]PROJECT_A[\'\"]/', $projectA->command);
        $this->assertMatchesRegularExpression('/--env=[\'\"]PROJECT_B[\'\"]/', $projectB->command);
        $this->assertNotSame($projectA->mutexName(), $projectB->mutexName());
        $this->assertTrue($projectA->withoutOverlapping);
        $this->assertTrue($projectB->withoutOverlapping);
        $this->assertFalse($projectA->runInBackground);
        $this->assertFalse($projectB->runInBackground);
        $this->assertCount(2, array_slice($schedule->events(), -2));
    }

    public function test_scheduled_push_keeps_legacy_command_without_an_environment_selector(): void
    {
        app()->instance('env', 'local');
        $_SERVER['argv'] = ['artisan', 'schedule:run'];

        $before = count(app(Schedule::class)->events());
        (new MonitorProvider($this->app))->boot();
        $events = app(Schedule::class)->events();
        $event  = $events[array_key_last($events)];

        $this->assertCount($before + 1, $events);
        $this->assertStringContainsString('moo:cloud:push', $event->command);
        $this->assertStringNotContainsString('--env', $event->command);
        $this->assertTrue($event->runInBackground);
    }

    private function scheduleFor(string $environment): object
    {
        app()->instance('env', $environment);
        $_SERVER['argv'] = ['artisan', 'schedule:run', '--env=' . $environment];

        $before = count(app(Schedule::class)->events());
        (new MonitorProvider($this->app))->boot();
        $events = app(Schedule::class)->events();

        $this->assertCount($before + 1, $events);

        return $events[array_key_last($events)];
    }
}
