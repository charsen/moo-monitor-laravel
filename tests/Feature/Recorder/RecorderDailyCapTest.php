<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Symfony\Component\Yaml\Yaml;

/**
 * daily_cap 回归锁:同一异常当天复发达到上限后,record() 跳过写盘(冻结 yaml)。
 * 这是为治理"高频复发又没 resolve 的异常 → yaml 每次刷 last_seen/count/meta.updated_at →
 * 多端 git sync 反复 commit(爆仓)"加的闸 —— 锁住 count 在上限处停住 + 次日翻篇归零两条不变量。
 */
function rmCapBuckets(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($base);
}

it('同一异常当天写盘达 daily_cap 后冻结(count 不再增长)', function () {
    $base = sys_get_temp_dir() . '/rt_cap_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['daily_cap' => 3]);
    try {
        $e    = new RuntimeException('boom cap');
        $hash = null;
        for ($i = 0; $i < 5; $i++) {
            $hash = $r->record($e); // 同一 $e → 同 hash;5 次复发只应写 3 次
        }
        $data = $r->get($hash);
        expect($data)->not->toBeNull();
        expect($data['count'])->toBe(3);              // 第 4/5 次被 cap,不 +count
        expect($data['daily']['count'])->toBe(3);
    } finally {
        rmCapBuckets($base);
    }
});

it('跨天后 daily 归零,可再记 daily_cap 次', function () {
    $base = sys_get_temp_dir() . '/rt_reset_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['daily_cap' => 2]);
    try {
        $e    = new RuntimeException('boom reset');
        $hash = $r->record($e); // count 1
        $r->record($e);         // count 2
        $r->record($e);         // 第 3 次被 cap,count 仍 2
        expect($r->get($hash)['count'])->toBe(2);

        // 篡改 daily.date 为历史日期,模拟跨天后再触发
        $path                      = $base . '/open/' . $hash . '.yaml';
        $tampered                  = $r->get($hash);
        $tampered['daily']['date'] = '2000-01-01';
        file_put_contents($path, Yaml::dump($tampered, 8, 2));

        $r->record($e); // 跨天 → 不 cap → 写盘
        $after = $r->get($hash);
        expect($after['count'])->toBe(3);             // 历史总数继续累加
        expect($after['daily']['count'])->toBe(1);    // 当天计数归零重计
        expect($after['daily']['date'])->not->toBe('2000-01-01');
    } finally {
        rmCapBuckets($base);
    }
});

it('daily_cap <= 0 时不限制(每次都写盘)', function () {
    $base = sys_get_temp_dir() . '/rt_nolimit_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['daily_cap' => 0]);
    try {
        $e    = new RuntimeException('boom nolimit');
        $hash = null;
        for ($i = 0; $i < 6; $i++) {
            $hash = $r->record($e);
        }
        expect($r->get($hash)['count'])->toBe(6);
    } finally {
        rmCapBuckets($base);
    }
});
