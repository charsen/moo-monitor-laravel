<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * Tier-5 perf:count() 改为按桶 glob(不再 allRows() 全量 parse 每个 yaml)。
 * 锁:计数 = 桶内 .yaml 文件数(status === bucket 不变量);count(null) = 全桶之和;不存在桶 → 0。
 * 顺带补 count() 覆盖(原本零测试)。
 */
function seedBuckets(string $base, array $bucketCounts, int $idLen): void
{
    foreach ($bucketCounts as $bucket => $k) {
        @mkdir($base . '/' . $bucket, 0755, true);
        for ($i = 0; $i < $k; $i++) {
            $name = str_pad((string) $i, $idLen, 'a', STR_PAD_LEFT);
            file_put_contents($base . '/' . $bucket . '/' . $name . '.yaml', "status: {$bucket}\n");
        }
    }
}

function rmBuckets(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($base);
}

it('RuntimeErrorRecorder::count 按桶 glob,count(null) 为全桶之和', function () {
    $base = sys_get_temp_dir() . '/rt_count_' . uniqid();
    seedBuckets($base, ['open' => 3, 'resolved' => 2, 'deleted' => 1], 12);
    file_put_contents($base . '/open/not-a-hash.yaml', "status: open\n");
    $r = new RuntimeErrorRecorder($base);
    try {
        expect($r->count('open'))->toBe(3);
        expect($r->count('resolved'))->toBe(2);
        expect($r->count('deleted'))->toBe(1);
        expect($r->count())->toBe(6);
        expect($r->count('nonexistent'))->toBe(0);     // 不存在的桶目录 → 0
    } finally {
        rmBuckets($base);
    }
});

it('SqlSlowRecorder::count 按桶 glob', function () {
    $base = sys_get_temp_dir() . '/sql_count_' . uniqid();
    seedBuckets($base, ['open' => 1, 'resolved' => 5], 12);
    file_put_contents($base . '/open/ABCDEF123456.yaml', "status: open\n");
    $r = new SqlSlowRecorder($base);
    try {
        expect($r->count('open'))->toBe(1);
        expect($r->count('resolved'))->toBe(5);
        expect($r->count())->toBe(6);     // deleted 桶不存在 → 0,不报错
    } finally {
        rmBuckets($base);
    }
});

it('RuntimeErrorRecorder::max_open gate ignores malformed yaml filenames', function () {
    $base = sys_get_temp_dir() . '/rt_gate_' . uniqid();
    @mkdir($base . '/open', 0755, true);
    file_put_contents($base . '/open/not-a-hash.yaml', "status: open\n");

    $r = new RuntimeErrorRecorder($base, ['enabled' => true, 'max_open' => 1]);
    try {
        $hash = $r->record(new RuntimeException('real error after dirty file'));

        expect($hash)->not->toBeNull()
            ->and($r->count('open'))->toBe(1);
    } finally {
        rmBuckets($base);
    }
});
