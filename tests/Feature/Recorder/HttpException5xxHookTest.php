<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Support\Facades\Route;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * P1-2 回归锁：HttpException 5xx 补采（矩阵 #5）。
 *
 * abort(500/502/503) 与第三方包抛的 5xx 全在框架 internalDontReport 里，reportable 主链不可见。
 * renderable 观察者补采：5xx 落盘 source=http_5xx；4xx 不落盘；返回 null 放行默认渲染 → 宿主响应体不变。
 */
class HttpException5xxHookTest extends TestCase
{
    private string $base;

    private RuntimeErrorRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('moo-monitor.runtime.enabled', true);
        config()->set('moo-monitor.exception.http_5xx_hook', true);

        $this->base     = sys_get_temp_dir() . '/rt_5xx_' . uniqid();
        $this->recorder = new RuntimeErrorRecorder($this->base, ['enabled' => true]);
        $this->app->instance(RuntimeErrorRecorder::class, $this->recorder);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/*/*.yaml') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @unlink($this->base . '/.gitignore');
        @rmdir($this->base);
        parent::tearDown();
    }

    /** @return array<int,string> */
    private function openFiles(): array
    {
        return glob($this->base . '/open/*.yaml') ?: [];
    }

    public function test_abort_503_is_recorded_via_http_5xx_and_response_unchanged(): void
    {
        Route::get('/moo-boom-503', fn () => abort(503, 'service smoking'));

        $this->get('/moo-boom-503')->assertStatus(503);

        $files = $this->openFiles();
        expect($files)->toHaveCount(1);

        $data = Yaml::parse((string) file_get_contents($files[0]));
        expect($data['meta']['source'])->toBe('http_5xx');
        expect($data['exception']['class'])->toContain('HttpException');
    }

    public function test_abort_404_is_not_recorded(): void
    {
        Route::get('/moo-boom-404', fn () => abort(404));

        $this->get('/moo-boom-404')->assertStatus(404);

        expect($this->openFiles())->toBeEmpty();
    }

    public function test_hook_can_be_disabled(): void
    {
        // 独立开关：关掉后 5xx 不再补采（钩子在 boot 时按配置注册，用 env 关闭）。
        putenv('MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK=false');
        $_ENV['MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK'] = 'false';
        $this->refreshApplication();
        config()->set('moo-monitor.runtime.enabled', true);
        $this->app->instance(RuntimeErrorRecorder::class, $this->recorder);

        Route::get('/moo-boom-503b', fn () => abort(503));
        $this->get('/moo-boom-503b')->assertStatus(503);

        expect($this->openFiles())->toBeEmpty();

        putenv('MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK');
        unset($_ENV['MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK']);
    }
}
