<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * P2-1 回归锁：daily_cap 与计数真实性解耦（失真 A）。
 *
 * 冻结期不写盘但把真实发生次数记进 cache overflow 计数器；次日首次通过 cap 闸写盘时回填进 count。
 * cache（testbench 默认 array store）可用时 count 反映真实累计；不可用退回现状偏低（best-effort）。
 */
class RecorderOverflowBackfillTest extends TestCase
{
    private function rm(string $base): void
    {
        foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @unlink($base . '/.gitignore');
        @rmdir($base);
    }

    /** 把 open 桶里某 hash 的 daily.date 篡改成历史日期，模拟跨天。 */
    private function ageToYesterday(string $base, string $hash): void
    {
        $path                  = $base . '/open/' . $hash . '.yaml';
        $data                  = Yaml::parseFile($path);
        $data['daily']['date'] = '2000-01-01';
        file_put_contents($path, Yaml::dump($data, 8, 2));
    }

    protected function setUp(): void
    {
        parent::setUp();
        // testbench 默认 cache store 是 database（无 cache 表 → 操作抛错）；回填 happy path 需要可用 cache。
        config()->set('cache.default', 'array');
    }

    public function test_runtime_overflow_backfills_count_next_day(): void
    {
        $base = sys_get_temp_dir() . '/rt_overflow_' . uniqid();
        $r    = new RuntimeErrorRecorder($base, ['enabled' => true, 'daily_cap' => 2]);
        try {
            $e    = new RuntimeException('hot error overflow');
            $hash = null;
            for ($i = 0; $i < 5; $i++) {
                $hash = $r->record($e); // 前 2 次写盘；第 3/4/5 次被 cap → overflow +3
            }
            expect($r->get($hash)['count'])->toBe(2);   // 盘上仍冻结在 cap

            // 次日首次复发：回填 overflow(3) → count = 2 + 3 + 1
            $this->ageToYesterday($base, $hash);
            $r->record($e);

            $after = $r->get($hash);
            expect($after['count'])->toBe(6);            // 2 冻结 + 3 溢出 + 1 新增
            expect($after['daily']['count'])->toBe(1);   // 当天计数归零重计
        } finally {
            $this->rm($base);
        }
    }

    public function test_slow_sql_overflow_backfills_count_next_day(): void
    {
        // 共享 TracksDailyCap，慢 SQL 同样回填。
        $base = sys_get_temp_dir() . '/sql_overflow_' . uniqid();
        $r    = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0, 'daily_cap' => 2]);
        try {
            $fire = fn () => $r->record('select * from `t` where `id` = ?', 'select * from `t` where `id` = 1', 200.0, '/app/F.php', 9);
            $hash = null;
            for ($i = 0; $i < 5; $i++) {
                $hash = $fire();
            }
            expect($r->get($hash)['count'])->toBe(2);

            $this->ageToYesterday($base, $hash);
            $fire();

            expect($r->get($hash)['count'])->toBe(6);
        } finally {
            $this->rm($base);
        }
    }

    public function test_cache_unavailable_degrades_to_frozen_count(): void
    {
        // cache 后端不可用（best-effort，P1-7② 决议）：溢出计数丢失、退回现状偏低（冻结在 cap），但不得抛错。
        config()->set('cache.default', 'database'); // testbench 无 cache 表 → 每次 cache 操作抛错被静默吞
        $base = sys_get_temp_dir() . '/rt_nocache_' . uniqid();
        $r    = new RuntimeErrorRecorder($base, ['enabled' => true, 'daily_cap' => 2]);
        try {
            $e    = new RuntimeException('overflow but cache down');
            $hash = null;
            for ($i = 0; $i < 5; $i++) {
                $hash = $r->record($e); // 不抛错即通过一半
            }
            $this->ageToYesterday($base, $hash);
            $r->record($e);

            // overflow 丢失 → 只 +1 新增，count = 2 + 0 + 1 = 3（退回现状偏低），未崩溃。
            expect($r->get($hash)['count'])->toBe(3);
        } finally {
            $this->rm($base);
        }
    }

    public function test_no_overflow_leaves_normal_count(): void
    {
        // 未触发 cap（daily_cap 大）时 overflow 恒 0，count 正常逐次 +1。
        $base = sys_get_temp_dir() . '/rt_nooverflow_' . uniqid();
        $r    = new RuntimeErrorRecorder($base, ['enabled' => true, 'daily_cap' => 100]);
        try {
            $e    = new RuntimeException('cool error');
            $hash = null;
            for ($i = 0; $i < 4; $i++) {
                $hash = $r->record($e);
            }
            expect($r->get($hash)['count'])->toBe(4);
        } finally {
            $this->rm($base);
        }
    }
}
