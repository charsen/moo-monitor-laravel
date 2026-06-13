<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Cloud\CloudSync;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;

/**
 * CloudSync 单元/集成测试 —— 本地 yaml → moo-scaffold-cloud intake 的增量推送编排。
 *
 * 用 Http::fake() 拦截出站请求(不真打网络),sandbox 临时 storage 隔离,游标文件落 sandbox。
 * 记录目录按包默认布局解析到 storage_path('moo-monitor/...'),sandbox 经 useStoragePath 注入。
 */
beforeEach(function () {
    $this->origStorage = storage_path();
    $this->sandbox     = sys_get_temp_dir() . '/monitor_cloudsync_' . uniqid();
    @mkdir($this->sandbox, 0755, true);
    app()->useStoragePath($this->sandbox);

    // 打开两类记录器 + 配好 cloud
    config([
        'moo-monitor.runtime.enabled'  => true,
        'moo-monitor.sql_slow.enabled' => true,
        'moo-monitor.cloud.enabled'    => true,
        'moo-monitor.cloud.base_url'   => 'https://cloud.test',
        'moo-monitor.cloud.token'      => 'tok-' . str_repeat('a1', 20), // ≥32, 含字母+数字
        'moo-monitor.cloud.batch'      => 100,
    ]);
    app()->forgetInstance(RuntimeErrorRecorder::class);
    app()->forgetInstance(SqlSlowRecorder::class);

    $this->cursor = $this->sandbox . '/cursor.json';
});

afterEach(function () {
    app()->useStoragePath($this->origStorage);
    cloudSync_rrmdir($this->sandbox);
});

function cloudSync_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? cloudSync_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function cloudSync_seedRuntime(string $msg = 'boom'): string
{
    return (string) app(RuntimeErrorRecorder::class)->record(new RuntimeException($msg));
}

function cloudSync_seedSlow(string $sql = 'select * from users where id = ?'): string
{
    return (string) app(SqlSlowRecorder::class)->record($sql, str_replace('?', '1', $sql), 250.0, '/app/Foo.php', 42);
}

// ─────────────────────────────────────────────────────────────────────────

it('推送 runtime 记录:命中正确端点,带 token + records[].hash', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    $hash = cloudSync_seedRuntime();
    $r    = (new CloudSync($this->cursor))->sync('runtimes');

    expect($r['ok'])->toBeTrue()
        ->and($r['scanned'])->toBe(1)
        ->and($r['changed'])->toBe(1)
        ->and($r['pushed'])->toBe(1)
        ->and($r['batches'])->toBe(1);

    Http::assertSent(function ($request) use ($hash) {
        return $request->url()   === 'https://cloud.test/' . CloudClient::PATH_RUNTIMES
            && $request['token'] === config('moo-monitor.cloud.token')
            && is_array($request['records'])
            && ($request['records'][0]['hash'] ?? null) === $hash;
    });
});

it('推送慢 SQL 记录到 slow-queries intake', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedSlow();
    $r = (new CloudSync($this->cursor))->sync('slow_sql');

    expect($r['ok'])->toBeTrue()->and($r['pushed'])->toBe(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.test/' . CloudClient::PATH_SLOW_QUERIES);
});

it('游标增量:第二次无新增 → 不再推送', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedRuntime();
    $sync = new CloudSync($this->cursor);

    $first = $sync->sync('runtimes');
    expect($first['pushed'])->toBe(1);

    $second = $sync->sync('runtimes');
    expect($second['ok'])->toBeTrue()
        ->and($second['changed'])->toBe(0)
        ->and($second['pushed'])->toBe(0);

    Http::assertSentCount(1); // 仅第一次发了请求
});

it('--all 忽略游标:即便无新增也全量重推', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedRuntime();
    $sync = new CloudSync($this->cursor);

    $sync->sync('runtimes');               // 建游标
    $r = $sync->sync('runtimes', all: true); // 全量

    expect($r['pushed'])->toBe(1);
    Http::assertSentCount(2);
});

it('dry-run 只统计不发请求', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedRuntime();
    $r = (new CloudSync($this->cursor))->sync('runtimes', all: false, dryRun: true);

    expect($r['ok'])->toBeTrue()->and($r['changed'])->toBe(1)->and($r['pushed'])->toBe(0);
    Http::assertNothingSent();
});

it('cloud 未启用 → skipped,不发请求', function () {
    config(['moo-monitor.cloud.enabled' => false]);
    Http::fake();

    cloudSync_seedRuntime();
    $r = (new CloudSync($this->cursor))->sync('runtimes');

    expect($r['skipped'])->toBeTrue();
    Http::assertNothingSent();
});

it('分类型开关:关掉 runtimes 推送 → skipped', function () {
    config(['moo-monitor.cloud.push.runtimes' => false]);
    Http::fake();

    cloudSync_seedRuntime();
    $r = (new CloudSync($this->cursor))->sync('runtimes');

    expect($r['skipped'])->toBeTrue();
    Http::assertNothingSent();
});

it('云端返回失败:ok=false 且游标不前进,下次可重试', function () {
    // 先失败一次,再成功一次
    Http::fakeSequence()
        ->push(['ok' => false, 'error' => '炸了'], 200)
        ->push(['ok' => true, 'saved' => 1], 200);

    cloudSync_seedRuntime();
    $sync = new CloudSync($this->cursor);

    $fail = $sync->sync('runtimes');
    expect($fail['ok'])->toBeFalse()->and($fail['error'])->toBe('炸了');
    expect(is_file($this->cursor))->toBeFalse(); // 失败 → 没写游标

    $retry = $sync->sync('runtimes'); // 同一条重试
    expect($retry['ok'])->toBeTrue()->and($retry['pushed'])->toBe(1);
});

it('坏 yaml 文件被跳过,不阻断整体', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    $hash = cloudSync_seedRuntime();
    // 投一个坏文件进 open 桶
    @file_put_contents(storage_path('moo-monitor/runtimes/open/zzzcorrupt.yaml'), "::: not yaml :::\n\t- broken");

    $r = (new CloudSync($this->cursor))->sync('runtimes');
    expect($r['ok'])->toBeTrue()->and($r['pushed'])->toBe(1); // 只有合法那条
});

it('推送以文件名 hash 为准,跳过非法文件名', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    $dir = storage_path('moo-monitor/runtimes/open');
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/aaaaaaaaaaaa.yaml', "hash: bbbbbbbbbbbb\nlast_seen: '" . now()->toIso8601String() . "'\ncount: 1\n");
    file_put_contents($dir . '/ABCDEF123456.yaml', "hash: ABCDEF123456\nlast_seen: '" . now()->toIso8601String() . "'\ncount: 1\n");

    $r = (new CloudSync($this->cursor))->sync('runtimes');

    expect($r['ok'])->toBeTrue()
        ->and($r['changed'])->toBe(1)
        ->and($r['pushed'])->toBe(1);
    Http::assertSent(fn ($request) => ($request['records'][0]['hash'] ?? null) === 'aaaaaaaaaaaa');
});

it('无 updated_at/last_seen 的 legacy 记录:推一次后游标越过,不再重推(mtime 兜底,2026-06-09 修)', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    // 手写一个无任何时间戳的 open 记录(legacy / 手改 yaml)
    $dir = storage_path('moo-monitor/runtimes/open');
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/abcdef123456.yaml', "hash: abcdef123456\nstatus: open\ncount: 1\n");

    $sync = new CloudSync($this->cursor);

    $first = $sync->sync('runtimes');
    expect($first['pushed'])->toBe(1);

    $second = $sync->sync('runtimes');
    // bug 版本:epoch=0 永远进 changed、maxCursor=null 游标永不写 → 每次重推(changed/pushed=1)
    expect($second['changed'])->toBe(0);
    expect($second['pushed'])->toBe(0);

    Http::assertSentCount(1);
});

it('增量读取以 yaml 时间戳为准,不因旧 mtime 漏推', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    $cursor = now();
    file_put_contents($this->cursor, json_encode(['runtimes' => $cursor->toIso8601String()]));

    $dir = storage_path('moo-monitor/runtimes/open');
    @mkdir($dir, 0755, true);
    $file = $dir . '/fedcba987654.yaml';
    file_put_contents($file, "hash: fedcba987654\nstatus: open\nlast_seen: '" . $cursor->copy()->addMinute()->toIso8601String() . "'\nmeta:\n  updated_at: '" . $cursor->copy()->addMinute()->toIso8601String() . "'\n");
    touch($file, time() - 86400 * 30);

    $r = (new CloudSync($this->cursor))->sync('runtimes');

    expect($r['ok'])->toBeTrue()
        ->and($r['changed'])->toBe(1)
        ->and($r['pushed'])->toBe(1);
    Http::assertSent(fn ($request) => ($request['records'][0]['hash'] ?? null) === 'fedcba987654');
});

it('writeState:先推 runtimes 再推 slow_sql,两个游标都保留(read-modify-write 不互覆盖)', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedRuntime();
    cloudSync_seedSlow();

    $sync = new CloudSync($this->cursor);
    $sync->sync('runtimes');
    $sync->sync('slow_sql');

    $state = json_decode((string) file_get_contents($this->cursor), true);
    // 第二次 writeState 必须先读回旧 state 再 merge,不能整体覆盖掉第一次的 runtimes 游标
    expect($state)->toHaveKey('runtimes');
    expect($state)->toHaveKey('slow_sql');
});

it('记录目录与游标目录自带自我屏蔽 .gitignore(storage 数据不入宿主 git)', function () {
    Http::fake(['*' => Http::response(['ok' => true, 'saved' => 1])]);

    cloudSync_seedRuntime();
    (new CloudSync)->sync('runtimes'); // 默认游标位置 → storage/moo-monitor/cloud-sync.json

    expect(file_get_contents(storage_path('moo-monitor/runtimes/.gitignore')))->toContain('*')
        ->and(file_get_contents(storage_path('moo-monitor/.gitignore')))->toContain('*');
});

// ─── pruneLocal:本地降级为临时缓冲 ──────────────────────────────────────

function cloudSync_writeRecord(string $bucket, string $hash, string $lastSeen, string $status = 'open'): void
{
    $dir = storage_path('moo-monitor/runtimes/' . $bucket);
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . $hash . '.yaml', "hash: {$hash}\nstatus: {$status}\nlast_seen: '{$lastSeen}'\ncount: 1\n");
}

it('pruneLocal:retention>0 → resolved(已上云)清、近期 open 留、deleted 不动', function () {
    cloudSync_writeRecord('open', 'aaaaaaaaaaaa', now()->toIso8601String());
    cloudSync_writeRecord('resolved', 'bbbbbbbbbbbb', now()->toIso8601String(), 'resolved');
    cloudSync_writeRecord('deleted', 'cccccccccccc', now()->toIso8601String(), 'deleted');
    // 模拟真实用法:prune 总在一次成功 push 之后跑,游标 = 已推水位(>= resolved 记录时间)。
    file_put_contents($this->cursor, json_encode(['runtimes' => now()->addDay()->toIso8601String()]));

    $res  = (new CloudSync($this->cursor))->pruneLocal('runtimes', 7);
    $base = storage_path('moo-monitor/runtimes');

    expect($res['purged'])->toBe(1)->and($res['prunedOpen'])->toBe(0)
        ->and(is_file($base . '/open/aaaaaaaaaaaa.yaml'))->toBeTrue()      // 近期 open 留作锚点
        ->and(is_file($base . '/resolved/bbbbbbbbbbbb.yaml'))->toBeFalse() // resolved 已上云 → 清
        ->and(is_file($base . '/deleted/cccccccccccc.yaml'))->toBeTrue();  // deleted 不动(未上云)
});

it('pruneLocal:resolved 但晚于游标(push 后才 resolve、未上云)→ 不清', function () {
    // 游标停在过去;resolved 记录时间戳更晚 = 上次 push 之后才被 resolve,尚未上云。
    file_put_contents($this->cursor, json_encode(['runtimes' => now()->subHour()->toIso8601String()]));
    cloudSync_writeRecord('resolved', 'bbbbbbbbbbbb', now()->toIso8601String(), 'resolved');

    $res = (new CloudSync($this->cursor))->pruneLocal('runtimes', 7);

    expect($res['purged'])->toBe(0)
        ->and(is_file(storage_path('moo-monitor/runtimes/resolved/bbbbbbbbbbbb.yaml')))->toBeTrue(); // 未上云 → 留着
});

it('pruneLocal:resolved 判断以上云游标为准,不信旧 mtime 快删', function () {
    file_put_contents($this->cursor, json_encode(['runtimes' => now()->subHour()->toIso8601String()]));

    $dir = storage_path('moo-monitor/runtimes/resolved');
    @mkdir($dir, 0755, true);
    $file = $dir . '/eeeeeeeeeeee.yaml';
    file_put_contents($file, "hash: eeeeeeeeeeee\nstatus: resolved\nlast_seen: '" . now()->toIso8601String() . "'\nmeta:\n  updated_at: '" . now()->toIso8601String() . "'\n");
    touch($file, time() - 86400 * 30);

    $res = (new CloudSync($this->cursor))->pruneLocal('runtimes', 7);

    expect($res['purged'])->toBe(0)
        ->and(is_file($file))->toBeTrue();
});

it('pruneLocal:无游标(从未成功推过)→ resolved 一律不删', function () {
    cloudSync_writeRecord('resolved', 'bbbbbbbbbbbb', now()->subDays(30)->toIso8601String(), 'resolved');

    $res = (new CloudSync($this->cursor))->pruneLocal('runtimes', 7);

    expect($res['purged'])->toBe(0)
        ->and(is_file(storage_path('moo-monitor/runtimes/resolved/bbbbbbbbbbbb.yaml')))->toBeTrue();
});

it('pruneLocal:retention=0 → 完全不回收(一个字节都不动)', function () {
    cloudSync_writeRecord('open', 'aaaaaaaaaaaa', now()->subDays(30)->toIso8601String()); // 即便很旧
    cloudSync_writeRecord('resolved', 'bbbbbbbbbbbb', now()->toIso8601String(), 'resolved');

    $res  = (new CloudSync($this->cursor))->pruneLocal('runtimes', 0);
    $base = storage_path('moo-monitor/runtimes');

    expect($res['purged'])->toBe(0)->and($res['prunedOpen'])->toBe(0)
        ->and(is_file($base . '/open/aaaaaaaaaaaa.yaml'))->toBeTrue()
        ->and(is_file($base . '/resolved/bbbbbbbbbbbb.yaml'))->toBeTrue();
});

it('pruneLocal:retention>0 清 stale open、保留近期 open', function () {
    cloudSync_writeRecord('open', 'aaaaaaaaaaaa', now()->subDays(30)->toIso8601String()); // 旧
    cloudSync_writeRecord('open', 'dddddddddddd', now()->toIso8601String());              // 新

    $res  = (new CloudSync($this->cursor))->pruneLocal('runtimes', 7);
    $base = storage_path('moo-monitor/runtimes');

    expect($res['prunedOpen'])->toBe(1)
        ->and(is_file($base . '/open/aaaaaaaaaaaa.yaml'))->toBeFalse() // dormant 清
        ->and(is_file($base . '/open/dddddddddddd.yaml'))->toBeTrue(); // 近期留
});
