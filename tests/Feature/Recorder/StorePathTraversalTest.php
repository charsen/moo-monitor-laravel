<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * ship-checklist #13 回归锁:user-controlled path 段(runtime/sql-slow hash)在拼进
 * file_get_contents / @unlink / @file_put_contents 前,必须经 store::path() 的 preg_match 严校验,
 * 拒 `..` / `/` / 大写 / 超长 / 空串等 traversal 输入。CLI 命令(moo:*:prune)也走 path(),
 * 所以核心 enforcement 落在 store 层 —— 这条测试直接锁 store::path(),路由改一刀也不漏。
 */
function invokeStorePath(object $store, string $key, string $bucket = 'open'): string
{
    $ref = new ReflectionMethod($store, 'path');
    $ref->setAccessible(true);

    return $ref->invoke($store, $key, $bucket);
}

function pathTraversalRm(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($base);
}

/** runtime / sql-slow:hash 必 [a-f0-9]{12} */
$evilHashes = ['../../etc/passwd', '..%2f..%2f', 'a/b', '..', '', 'ABCDEF123456', 'abcdef12345', str_repeat('a', 13), "abcdef123456\0"];

it('RuntimeErrorRecorder::path rejects traversal / malformed hashes', function () use ($evilHashes) {
    $store = new RuntimeErrorRecorder(sys_get_temp_dir() . '/rt_' . uniqid());
    foreach ($evilHashes as $evil) {
        expect(fn () => invokeStorePath($store, $evil))
            ->toThrow(InvalidArgumentException::class, '', "should reject: {$evil}");
    }
});

it('RuntimeErrorRecorder::path accepts a valid 12-hex hash', function () {
    $store = new RuntimeErrorRecorder(sys_get_temp_dir() . '/rt_' . uniqid());
    expect(invokeStorePath($store, 'abcdef012345', 'resolved'))
        ->toContain('/resolved/abcdef012345.yaml');
});

it('SqlSlowRecorder::path rejects traversal / malformed hashes', function () use ($evilHashes) {
    $store = new SqlSlowRecorder(sys_get_temp_dir() . '/sql_' . uniqid());
    foreach ($evilHashes as $evil) {
        expect(fn () => invokeStorePath($store, $evil))
            ->toThrow(InvalidArgumentException::class, '', "should reject: {$evil}");
    }
});

it('SqlSlowRecorder::path accepts a valid 12-hex hash', function () {
    $store = new SqlSlowRecorder(sys_get_temp_dir() . '/sql_' . uniqid());
    expect(invokeStorePath($store, '0123456789ab'))->toContain('0123456789ab.yaml');
});

it('RuntimeErrorRecorder::list skips malformed yaml filenames', function () {
    $base = sys_get_temp_dir() . '/rt_list_' . uniqid();
    @mkdir($base . '/open', 0755, true);
    file_put_contents($base . '/open/abcdef123456.yaml', "hash: abcdef123456\nlast_seen: '2026-06-12T12:00:00+08:00'\ncount: 1\n");
    file_put_contents($base . '/open/not-a-hash.yaml', "hash: not-a-hash\n");

    try {
        $rows = (new RuntimeErrorRecorder($base))->list('open');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['hash'])->toBe('abcdef123456');
    } finally {
        pathTraversalRm($base);
    }
});

it('SqlSlowRecorder::list skips malformed yaml filenames', function () {
    $base = sys_get_temp_dir() . '/sql_list_' . uniqid();
    @mkdir($base . '/open', 0755, true);
    file_put_contents($base . '/open/0123456789ab.yaml', "hash: 0123456789ab\nlast_seen: '2026-06-12T12:00:00+08:00'\ncount: 1\n");
    file_put_contents($base . '/open/ABCDEF123456.yaml', "hash: ABCDEF123456\n");

    try {
        $rows = (new SqlSlowRecorder($base))->list('open');

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['hash'])->toBe('0123456789ab');
    } finally {
        pathTraversalRm($base);
    }
});
