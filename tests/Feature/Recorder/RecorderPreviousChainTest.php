<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;

/**
 * P1-5 回归锁：异常链 previous（失真 E）。
 *
 * 包装异常（QueryException 里的 PDOException 等）过去丢根因。extractException 增采最多 3 层 previous，
 * 每层同款双层脱敏；第 4 层及以下截断。hash 仍按最外层算（聚合稳定性不变），云端契约只增可选字段。
 */
function rmPrevBuckets(string $base): void
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

it('四层 previous 链只采前 3 层，超出截断、message 已脱敏', function () {
    $base = sys_get_temp_dir() . '/rt_prev_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['enabled' => true, 'mask_keys' => ['token']]);
    try {
        $l5 = new RuntimeException('deepest level5 should be truncated');
        $l4 = new RuntimeException('level4 root', 0, $l5);
        $l3 = new RuntimeException('level3 wrap', 0, $l4);
        $l2 = new RuntimeException('level2 Bearer abc123 token=sk-live-secret', 0, $l3);
        $l1 = new RuntimeException('outermost level1', 0, $l2);

        $hash = $r->record($l1);
        $data = $r->get($hash);

        // hash / 最外层归属不变
        expect($data['exception']['class'])->toBe(RuntimeException::class);
        expect($data['exception']['message'])->toContain('outermost level1');

        $previous = $data['exception']['previous'];
        expect($previous)->toHaveCount(3);                      // 只前 3 层
        expect($previous[0]['message'])->toContain('level2');
        expect($previous[1]['message'])->toContain('level3');
        expect($previous[2]['message'])->toContain('level4');
        // 第 4 层（level5）被截断
        foreach ($previous as $p) {
            expect($p['message'])->not->toContain('level5');
            expect($p)->toHaveKeys(['class', 'message', 'file', 'line']);
        }
        // 每层脱敏不降级
        expect($previous[0]['message'])->toContain('Bearer ***');
        expect($previous[0]['message'])->not->toContain('sk-live-secret');
        expect($previous[0]['message'])->not->toContain('abc123');
    } finally {
        rmPrevBuckets($base);
    }
});

it('无 previous 的单异常不产生 previous 字段（云端契约只增字段）', function () {
    $base = sys_get_temp_dir() . '/rt_noprev_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $hash = $r->record(new RuntimeException('lonely'));
        $data = $r->get($hash);
        expect($data['exception'])->not->toHaveKey('previous');
    } finally {
        rmPrevBuckets($base);
    }
});
