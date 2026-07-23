<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Cloud\CloudSync;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Symfony\Component\Yaml\Yaml;

/**
 * 同一 Laravel host 通过 `artisan --env=XXX` 承载多个 Cloud 项目时，本地缓冲与同步状态必须隔离。
 *
 * auto scope 只在 Artisan 环境名与 config('app.env') 不同时启用，普通单环境部署继续沿用旧路径。
 */
beforeEach(function () {
    $this->origStorage     = storage_path();
    $this->origEnvironment = (string) app()->environment();
    $this->origArgv        = $_SERVER['argv'] ?? null;
    $this->sandbox         = sys_get_temp_dir() . '/monitor_storage_scope_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    app()->useStoragePath($this->sandbox);

    config([
        'app.env'                      => 'local',
        'moo-monitor.storage_scope'    => 'auto',
        'moo-monitor.runtime.enabled'  => true,
        'moo-monitor.sql_slow.enabled' => true,
        'moo-monitor.cloud.enabled'    => true,
        'moo-monitor.cloud.base_url'   => 'https://cloud.test',
        'moo-monitor.cloud.token'      => 'tok-' . str_repeat('a1', 20),
        'moo-monitor.cloud.batch'      => 100,
    ]);
});

afterEach(function () {
    app()->instance('env', $this->origEnvironment);
    if ($this->origArgv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $this->origArgv;
    }
    app()->useStoragePath($this->origStorage);
    storageScope_rrmdir($this->sandbox);
});

function storageScope_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $path = $dir . '/' . $file;
        is_dir($path) ? storageScope_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function storageScope_use(string $environment): void
{
    app()->instance('env', $environment);
    $_SERVER['argv'] = ['artisan', 'moo:cloud:push', '--env=' . $environment];
}

function storageScope_runtime(string $message): string
{
    return (string) (new RuntimeErrorRecorder)->record(new RuntimeException($message));
}

function storageScope_slow(string $sql): string
{
    return (string) (new SqlSlowRecorder)->record($sql, str_replace('?', '1', $sql), 250.0, '/app/Scoped.php', 42);
}

function storageScope_writeRuntime(string $scope, string $bucket, string $hash, string $updatedAt): string
{
    $dir = storage_path("moo-monitor/runtimes--{$scope}/{$bucket}");
    @mkdir($dir, 0755, true);
    $file = $dir . '/' . $hash . '.yaml';
    file_put_contents($file, Yaml::dump([
        'hash'      => $hash,
        'status'    => $bucket,
        'last_seen' => $updatedAt,
        'count'     => 1,
        'meta'      => ['updated_at' => $updatedAt],
    ], 8, 2));

    return $file;
}

it('auto scope 隔离 runtime 与 slow SQL 桶，相同 hash 不跨项目聚合', function () {
    storageScope_use('ALPHA');
    $runtimeHashA = storageScope_runtime('same runtime');
    $slowHashA    = storageScope_slow('select * from users where id = ?');

    storageScope_use('BETA');
    $runtimeHashB = storageScope_runtime('same runtime');
    $slowHashB    = storageScope_slow('select * from users where id = ?');

    expect($runtimeHashB)->toBe($runtimeHashA)
        ->and($slowHashB)->toBe($slowHashA);

    $runtimeA = storage_path("moo-monitor/runtimes--alpha/open/{$runtimeHashA}.yaml");
    $runtimeB = storage_path("moo-monitor/runtimes--beta/open/{$runtimeHashB}.yaml");
    $slowA    = storage_path("moo-monitor/sql-slows--alpha/open/{$slowHashA}.yaml");
    $slowB    = storage_path("moo-monitor/sql-slows--beta/open/{$slowHashB}.yaml");

    expect(is_file($runtimeA))->toBeTrue()
        ->and(is_file($runtimeB))->toBeTrue()
        ->and((int) Yaml::parseFile($runtimeA)['count'])->toBe(1)
        ->and((int) Yaml::parseFile($runtimeB)['count'])->toBe(1)
        ->and(is_file($slowA))->toBeTrue()
        ->and(is_file($slowB))->toBeTrue()
        ->and((int) Yaml::parseFile($slowA)['count'])->toBe(1)
        ->and((int) Yaml::parseFile($slowB)['count'])->toBe(1);
});

it('auto scope 在环境名一致时保持旧路径，显式 false 可关闭隔离', function () {
    storageScope_use('local');
    $legacyHash = storageScope_runtime('legacy path');
    expect(is_file(storage_path("moo-monitor/runtimes/open/{$legacyHash}.yaml")))->toBeTrue();

    config(['moo-monitor.storage_scope' => false]);
    storageScope_use('ALPHA');
    $disabledHash = storageScope_runtime('disabled scope');
    expect(is_file(storage_path("moo-monitor/runtimes/open/{$disabledHash}.yaml")))->toBeTrue();
});

it('显式 scope 经规范化后生效，不依赖 app environment', function () {
    config(['moo-monitor.storage_scope' => 'Project North']);
    storageScope_use('local');

    $hash = storageScope_runtime('explicit scope');

    expect(is_file(storage_path("moo-monitor/runtimes--project-north/open/{$hash}.yaml")))->toBeTrue();
});

it('A push 只读取 A 的 runtime/slow SQL，--all 也不能越过 scope', function () {
    storageScope_use('BETA');
    $runtimeB = storageScope_runtime('beta runtime');
    $slowB    = storageScope_slow('select * from beta where id = ?');

    storageScope_use('ALPHA');
    $runtimeA = storageScope_runtime('alpha runtime');
    $slowA    = storageScope_slow('select * from alpha where id = ?');

    Http::fake(function ($request) {
        return Http::response([
            'ok'       => true,
            'saved'    => count($request['records']),
            'filtered' => 0,
            'skipped'  => 0,
        ]);
    });

    $sync       = new CloudSync;
    $runtimeRes = $sync->sync('runtimes', all: true);
    $slowRes    = $sync->sync('slow_sql', all: true);

    expect($runtimeRes['scanned'])->toBe(1)
        ->and($runtimeRes['pushed'])->toBe(1)
        ->and($slowRes['scanned'])->toBe(1)
        ->and($slowRes['pushed'])->toBe(1);

    Http::assertSent(function ($request) use ($runtimeA, $runtimeB) {
        if (! str_contains($request->url(), '/runtimes')) {
            return false;
        }
        $hashes = array_column($request['records'], 'hash');

        return $hashes === [$runtimeA] && ! in_array($runtimeB, $hashes, true);
    });
    Http::assertSent(function ($request) use ($slowA, $slowB) {
        if (! str_contains($request->url(), '/slow-queries')) {
            return false;
        }
        $hashes = array_column($request['records'], 'hash');

        return $hashes === [$slowA] && ! in_array($slowB, $hashes, true);
    });
});

it('A 的较新游标不抑制 B 的较早记录，默认 cursor/lock 文件按 scope 隔离', function () {
    storageScope_use('BETA');
    $betaHash = storageScope_runtime('older beta runtime');
    usleep(2000);

    storageScope_use('ALPHA');
    $alphaHash = storageScope_runtime('newer alpha runtime');

    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0])]);

    config(['moo-monitor.cloud.token' => 'alpha-' . str_repeat('a1', 20)]);
    $alpha = (new CloudSync)->sync('runtimes');

    storageScope_use('BETA');
    config(['moo-monitor.cloud.token' => 'beta-' . str_repeat('b2', 20)]);
    $beta = (new CloudSync)->sync('runtimes');

    expect($alpha['pushed'])->toBe(1)
        ->and($beta['pushed'])->toBe(1)
        ->and(is_file(storage_path('moo-monitor/cloud-sync--alpha.json')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/cloud-sync--beta.json')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/cloud-sync--alpha.json.runtimes.sync.lock')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/cloud-sync--beta.json.runtimes.sync.lock')))->toBeTrue();

    Http::assertSent(fn ($request) => $request['token'] === config('moo-monitor.cloud.token') && ($request['records'][0]['hash'] ?? null) === $betaHash);
    expect($alphaHash)->not->toBe($betaHash);
});

it('A 的 partial ack 不确认 B 中相同 hash/version 的记录', function () {
    storageScope_use('ALPHA');
    storageScope_runtime('shared saved');
    storageScope_runtime('shared retry');

    storageScope_use('BETA');
    storageScope_runtime('shared saved');
    storageScope_runtime('shared retry');

    storageScope_use('ALPHA');
    Http::fake(function ($request) {
        $records = $request['records'];

        return Http::response([
            'ok'       => true,
            'saved'    => 1,
            'filtered' => 0,
            'skipped'  => 1,
            'results'  => [
                ['index' => 0, 'hash' => $records[0]['hash'], 'status' => 'saved', 'retryable' => false, 'reason' => null],
                ['index' => 1, 'hash' => $records[1]['hash'], 'status' => 'skipped', 'retryable' => true, 'reason' => 'upsert_failed'],
            ],
        ]);
    });

    $alpha = (new CloudSync)->sync('runtimes');
    expect($alpha['ok'])->toBeFalse()
        ->and(is_file(storage_path('moo-monitor/cloud-sync--alpha.json.acks')))->toBeTrue();

    storageScope_use('BETA');
    $beta = (new CloudSync)->sync('runtimes', dryRun: true);

    expect($beta['changed'])->toBe(2)
        ->and(is_file(storage_path('moo-monitor/cloud-sync--beta.json.acks')))->toBeFalse();
});

it('prune 只回收当前 scope 的 resolved 文件', function () {
    $future = now()->addDay()->toIso8601String();
    $alpha  = storageScope_writeRuntime('alpha', 'resolved', 'aaaaaaaaaaaa', now()->toIso8601String());
    $beta   = storageScope_writeRuntime('beta', 'resolved', 'bbbbbbbbbbbb', now()->toIso8601String());
    file_put_contents(storage_path('moo-monitor/cloud-sync--alpha.json'), json_encode(['runtimes' => $future]));

    storageScope_use('ALPHA');
    $result = (new CloudSync)->pruneLocal('runtimes', 7);

    expect($result['purged'])->toBe(1)
        ->and(is_file($alpha))->toBeFalse()
        ->and(is_file($beta))->toBeTrue();
});
