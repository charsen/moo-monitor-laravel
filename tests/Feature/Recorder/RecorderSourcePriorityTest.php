<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Symfony\Component\Yaml\Yaml;

/**
 * P2-2 回归锁：source 优先级 ping-pong + tagSource 绕过冻结（失真 B）。
 *
 * ① meta.source 只升不降：refresh 带来的低优先级来源不得把高优先级来源降回去；sources 收全部见过来源并集。
 * ② tagSource 两道闸：来源无实质变化不写盘；当天已达 cap（冻结）不写盘（meta 变化随次日回填一起落）。
 * ③ 优先级真源单一：ExceptionDispatcher 复用 RuntimeErrorRecorder::sourceRank。
 */
function rmSrcBuckets(string $base): void
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

it('source 只升不降：先 queue_failed 后 log_context 仍是 queue_failed，sources 收并集', function () {
    $base = sys_get_temp_dir() . '/rt_src_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $mk = fn () => new RuntimeException('ping pong source'); // 同一行 → 同 hash

        $hash = $rec->record($mk(), null, 'queue_failed', ['connection' => 'redis']);
        $rec->record($mk(), null, 'log_context', ['log_level' => 'error']); // 同 hash refresh，低优先级

        $row = Yaml::parseFile($base . '/open/' . $hash . '.yaml');
        expect($row['count'])->toBe(2);
        expect($row['meta']['source'])->toBe('queue_failed');          // 未被降级
        expect($row['meta']['sources'])->toContain('queue_failed')->toContain('log_context');
    } finally {
        rmSrcBuckets($base);
    }
});

it('tagSource 来源无变化不写盘（gate①：文件字节不变）', function () {
    $base = sys_get_temp_dir() . '/rt_tag1_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $e    = new RuntimeException('same source tag');
        $hash = $rec->record($e, null, 'queue_failed', ['connection' => 'redis']);
        $path = $base . '/open/' . $hash . '.yaml';

        $before = md5_file($path);
        $rec->tagSource($e, 'queue_failed', ['connection' => 'redis']); // 完全相同 → 免写盘
        expect(md5_file($path))->toBe($before);
    } finally {
        rmSrcBuckets($base);
    }
});

it('冻结后 tagSource 不写盘（gate②：文件字节不变、source 不升级）', function () {
    $base = sys_get_temp_dir() . '/rt_tag2_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true, 'daily_cap' => 1]);
    try {
        $e    = new RuntimeException('frozen tag');
        $hash = $rec->record($e, null, 'reportable'); // count 1 → daily.count 1 = cap 1 → 已冻结
        $path = $base . '/open/' . $hash . '.yaml';

        $before = md5_file($path);
        $rec->tagSource($e, 'queue_failed', ['connection' => 'redis']); // 冻结 → 免写盘
        expect(md5_file($path))->toBe($before);

        $row = Yaml::parseFile($path);
        expect($row['meta']['source'])->toBe('reportable'); // 冻结期未升级
    } finally {
        rmSrcBuckets($base);
    }
});

it('优先级真源单一：ExceptionDispatcher 与 recorder 同表', function () {
    expect(RuntimeErrorRecorder::sourceRank('queue_failed'))->toBe(30);
    expect(RuntimeErrorRecorder::sourceRank('schedule_exit'))->toBe(28);
    expect(RuntimeErrorRecorder::sourceRank('http_5xx'))->toBe(25);
    expect(RuntimeErrorRecorder::sourceRank('log_context'))->toBe(20);
    expect(RuntimeErrorRecorder::sourceRank('log_message'))->toBe(15);
    expect(RuntimeErrorRecorder::sourceRank('reportable'))->toBe(10);
    expect(RuntimeErrorRecorder::sourceRank('self_test'))->toBe(0); // 未知来源
});
