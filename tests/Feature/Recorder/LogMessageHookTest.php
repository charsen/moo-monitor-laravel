<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mooeen\Monitor\LoggedErrorMessage;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * P1-1 回归锁：字符串化异常进日志（矩阵 #4）。
 *
 * Log::error($e) / Log::error('失败: '.$e->getMessage()) 在 MessageLogged 之前已被 formatMessage
 * 强转 string（vendor Logger.php:270-276），context 无 exception 对象 —— log_context 钩子全漏。
 * log_message 钩子按调用点合成 LoggedErrorMessage 进同一 record 管道。含防回环双保险测试。
 */
class LogMessageHookTest extends TestCase
{
    /** 换成 NullHandler channel：触发 MessageLogged 事件但不写日志文件（monolog 驱动仍带 events dispatcher）。 */
    private function useNullLogChannel(): void
    {
        config()->set('logging.channels.moo_null', ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]);
        config()->set('logging.default', 'moo_null');
    }

    public function test_plain_string_error_log_records_via_log_message_source(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('error', 'boom: db timeout', []));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(LoggedErrorMessage::class),
            Mockery::any(),
            'log_message',
            Mockery::on(fn (array $meta) => ($meta['log_level'] ?? null) === 'error'
                && str_contains((string) ($meta['log_message'] ?? ''), 'db timeout')),
        );
    }

    public function test_stringified_exception_form_is_recorded(): void
    {
        // Log::error($e) 形态：message 已是 (string) $e（含类名 + trace），context 仍无 exception 对象。
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        $e = new RuntimeException('deep failure');
        event(new MessageLogged('error', (string) $e, []));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(LoggedErrorMessage::class),
            Mockery::any(),
            'log_message',
            Mockery::any(),
        );
    }

    public function test_log_with_real_exception_object_is_left_to_log_context_hook(): void
    {
        // 带真异常对象的应走 log_context（信息量更高），log_message 钩子不得重复采成 LoggedErrorMessage。
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('error', 'has object', ['exception' => new RuntimeException('real')]));

        $spy->shouldHaveReceived('record')->once()->with(
            Mockery::type(RuntimeException::class),
            Mockery::any(),
            'log_context',
            Mockery::any(),
        );
        // 只此一次；不会再多一条 log_message 合成记录。
    }

    public function test_empty_and_below_threshold_levels_are_skipped(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('error', '   ', []));      // 纯空白
        event(new MessageLogged('info', 'just info', [])); // 级别不在白名单
        event(new MessageLogged('warning', 'warn', []));   // 默认白名单不含 warning

        $spy->shouldNotHaveReceived('record');
    }

    public function test_internal_marked_logs_are_not_captured(): void
    {
        // 防回环①：本包 safeLog 出的日志带 moo_monitor_internal 标记，钩子见标记即跳过。
        config()->set('moo-monitor.runtime.enabled', true);

        $spy = Mockery::spy(RuntimeErrorRecorder::class);
        $this->app->instance(RuntimeErrorRecorder::class, $spy);

        event(new MessageLogged('error', 'runtime-recorder: 写盘失败（目录不可写？）', ['moo_monitor_internal' => true]));

        $spy->shouldNotHaveReceived('record');
    }

    public function test_reentrancy_guard_blocks_stray_unmarked_log_during_recording(): void
    {
        // 防回环②：采集过程中若产生一条「无内部标记」的 error 日志，static 重入闸挡住二次合成。
        config()->set('moo-monitor.runtime.enabled', true);
        $this->useNullLogChannel();

        $rec = new class(sys_get_temp_dir() . '/rt_loop_' . uniqid(), ['enabled' => true]) extends RuntimeErrorRecorder
        {
            public int $calls = 0;

            public function record(\Throwable $e, ?Request $request = null, string $source = 'reportable', array $meta = []): ?string
            {
                $this->calls++;
                // 模拟采集途中冒出一条无标记 error 日志（若无 static 重入闸会二次触发本钩子 → calls=2）
                logger()->error('stray error during recording');

                return 'deadbeef0000';
            }
        };
        $this->app->instance(RuntimeErrorRecorder::class, $rec);

        Log::error('outer boom');

        expect($rec->calls)->toBe(1);
    }

    public function test_string_log_records_at_call_site_with_stable_hash(): void
    {
        config()->set('moo-monitor.runtime.enabled', true);
        $this->useNullLogChannel();

        $base = sys_get_temp_dir() . '/rt_logmsg_' . uniqid();
        $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
        $this->app->instance(RuntimeErrorRecorder::class, $rec);

        try {
            $callLine = __LINE__ + 1;
            $log      = fn () => Log::error('boom: db timeout');
            $log();
            $log(); // 同一 arrow fn 调用点 → 同 file:line → hash 稳定 → 聚合 count=2、单文件

            $files = glob($base . '/open/*.yaml') ?: [];
            expect($files)->toHaveCount(1);

            $data = Yaml::parse((string) file_get_contents($files[0]));
            expect($data['exception']['class'])->toBe(LoggedErrorMessage::class);
            expect($data['exception']['file'])->toContain('LogMessageHookTest');
            expect($data['exception']['line'])->toBe($callLine);
            expect($data['count'])->toBe(2);
            expect($data['meta']['source'])->toBe('log_message');
            expect($data['source_snippet']['code'])->toContain('boom: db timeout');
        } finally {
            foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
                @rmdir($d);
            }
            @unlink($base . '/.gitignore');
            @rmdir($base);
        }
    }
}
