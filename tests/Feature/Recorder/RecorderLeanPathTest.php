<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Symfony\Component\ErrorHandler\Error\FatalError;

/**
 * P1-4 回归锁：fatal/OOM 的 lean path（矩阵 #7）。
 *
 * Laravel shutdown 兜底只留 32KB 保留内存，重采集路径（file() 整读 + trace 五连正则 + payload 递归脱敏）
 * 在 OOM 语境极易二次耗尽。危险语境（FatalError / 内存逼近上限）走 lean：跳过重采集、脱敏不降级、meta.lean=true。
 * 真实 OOM 无法在测试可靠复现 —— 这里按类型（FatalError）驱动，内存比率分支的解析靠代码审阅保障。
 */
function rmLeanBuckets(string $base): void
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

it('FatalError 走 lean path：最小记录、脱敏不降级、meta.lean=true', function () {
    $base = sys_get_temp_dir() . '/rt_lean_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['enabled' => true, 'mask_keys' => ['token']]);
    try {
        // message 含 Bearer token + token=xxx，且超 512 长 —— 断言仍脱敏、且被截断。
        $secret  = 'Bearer abc123def456 token=sk-live-supersecret';
        $message = 'Allowed memory size exhausted ' . $secret . ' ' . str_repeat('x', 900);
        $file    = ['type' => E_ERROR, 'message' => $message, 'file' => '/app/Kernel.php', 'line' => 7];

        $hash = $r->record(new FatalError($message, 0, $file));
        expect($hash)->not->toBeNull();

        $data = $r->get($hash);
        expect($data['meta']['lean'])->toBeTrue();
        // 脱敏不降级：密钥不得残留
        expect($data['exception']['message'])->not->toContain('abc123def456');
        expect($data['exception']['message'])->not->toContain('sk-live-supersecret');
        expect($data['exception']['message'])->toContain('Bearer ***');
        // message 截 512
        expect(mb_strlen($data['exception']['message']))->toBeLessThanOrEqual(512);
        // 重采集全部跳过
        expect($data['source_snippet']['code'])->toBe('');
        expect($data['payload'])->toBe([]);
        expect($data['trace']['app_frames'])->toBe([]);
        expect(strlen((string) $data['trace']['full']))->toBeLessThanOrEqual(4096);
        // request 只留 method/url 两键（丢 ip/user/guard 采集）；具体 console→null 归属见 P2-3。
        expect(array_keys($data['request']))->toBe(['method', 'url']);
    } finally {
        rmLeanBuckets($base);
    }
});

it('常规异常不走 lean path（source_snippet 有内容、无 meta.lean）', function () {
    $base = sys_get_temp_dir() . '/rt_full_' . uniqid();
    $r    = new RuntimeErrorRecorder($base, ['enabled' => true]);
    try {
        $hash = $r->record(new RuntimeException('normal error'));
        $data = $r->get($hash);
        expect($data['meta']['lean'] ?? null)->toBeNull();
        expect($data['source_snippet']['code'])->not->toBe('');   // 常规路径读了源码
        expect($data['trace'])->toHaveKey('app_frames');
    } finally {
        rmLeanBuckets($base);
    }
});
