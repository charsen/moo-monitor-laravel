<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Symfony\Component\Yaml\Yaml;

/**
 * 安全回归锁:慢 SQL 落盘的 request.url 跟 runtime 一样按 mask_keys 脱敏(共享 MasksSensitiveUrl trait)。
 * 防止「runtime 脱了、慢 SQL 没脱」的密钥泄露 —— 慢查询发生在带 token/secret/api_key 的 URL 上时,
 * 这些值绝不能明文落进 storage/moo-monitor/sql-slows/*.yaml 再被 moo:cloud:push 推到云端。
 */
function rmSqlMaskBase(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($base);
}

it('SqlSlowRecorder masks sensitive query params in request.url', function () {
    $base = sys_get_temp_dir() . '/sql_mask_' . uniqid();
    $rec  = new SqlSlowRecorder($base, [
        'enabled'      => true,
        'threshold_ms' => 0,
        'mask_keys'    => ['token', 'secret', 'api_key', 'password'],
    ]);
    $request = Request::create('http://localhost/api/orders?token=abc123&api_key=zzz&page=1', 'GET');

    try {
        $hash = $rec->record('select * from `orders`', 'select * from `orders`', 250.0, '/app/Http/Foo.php', 20, request: $request);
        expect($hash)->not->toBeNull();

        $files = glob($base . '/open/*.yaml');
        expect($files)->toHaveCount(1);

        $url = Yaml::parseFile($files[0])['request']['url'] ?? '';
        expect($url)
            ->toContain('token=***')
            ->toContain('api_key=***')
            ->toContain('page=1')        // 非敏感参数保留
            ->not->toContain('abc123')   // 密钥值不落盘
            ->not->toContain('zzz');
    } finally {
        rmSqlMaskBase($base);
    }
});

it('SqlSlowRecorder leaves a query-less url untouched', function () {
    $base    = sys_get_temp_dir() . '/sql_mask_' . uniqid();
    $rec     = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0, 'mask_keys' => ['token']]);
    $request = Request::create('http://localhost/api/orders', 'GET');

    try {
        $rec->record('select 1', 'select 1', 120.0, '/app/Foo.php', 5, request: $request);
        $files = glob($base . '/open/*.yaml');
        $url   = Yaml::parseFile($files[0])['request']['url'] ?? '';
        expect($url)->toBe('http://localhost/api/orders');
    } finally {
        rmSqlMaskBase($base);
    }
});
