<?php declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * moo:monitor:migrate —— scaffold ≤3.8 旧布局(base_path('scaffold/...') + storage/app/scaffold 游标)
 * 平移到新布局(storage/moo-monitor/...)。锁:平移、同 hash 新者胜、游标合并取小、幂等、.env 体检。
 */
beforeEach(function () {
    $this->origStorage = storage_path();
    $this->origBase    = base_path();
    $this->sandbox     = sys_get_temp_dir() . '/monitor_migrate_' . uniqid();
    @mkdir($this->sandbox . '/base', 0755, true);
    @mkdir($this->sandbox . '/storage', 0755, true);
    app()->setBasePath($this->sandbox . '/base');
    app()->useStoragePath($this->sandbox . '/storage');
});

afterEach(function () {
    app()->setBasePath($this->origBase);
    app()->useStoragePath($this->origStorage);
    migrateCmd_rrmdir($this->sandbox);
});

function migrateCmd_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . '/' . $f;
        is_dir($p) ? migrateCmd_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function migrateCmd_seedOld(string $type, string $bucket, string $hash, string $updatedAt): void
{
    $dir = base_path('scaffold/' . $type . '/' . $bucket);
    @mkdir($dir, 0755, true);
    file_put_contents($dir . '/' . $hash . '.yaml', Yaml::dump([
        'hash'   => $hash,
        'status' => $bucket,
        'count'  => 1,
        'meta'   => ['updated_at' => $updatedAt],
    ], 8, 2));
}

it('没有旧数据 → 幂等提示,不报错', function () {
    $this->artisan('moo:monitor:migrate')
        ->expectsOutputToContain('没有发现需要迁移的旧数据')
        ->assertExitCode(0);
});

it('平移三桶 yaml 到 storage/moo-monitor/,旧目录清空删除', function () {
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');
    migrateCmd_seedOld('runtimes', 'resolved', 'bbbbbbbbbbbb', '2026-06-01T00:00:00+00:00');
    migrateCmd_seedOld('sql-slows', 'open', 'cccccccccccc', '2026-06-01T00:00:00+00:00');

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);

    expect(is_file(storage_path('moo-monitor/runtimes/open/aaaaaaaaaaaa.yaml')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/runtimes/resolved/bbbbbbbbbbbb.yaml')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/sql-slows/open/cccccccccccc.yaml')))->toBeTrue()
        ->and(is_dir(base_path('scaffold/runtimes')))->toBeFalse()  // 旧目录已删
        ->and(is_dir(base_path('scaffold/sql-slows')))->toBeFalse()
        ->and(is_file(storage_path('moo-monitor/runtimes/.gitignore')))->toBeTrue(); // 新目录自带屏蔽
});

it('同 hash 两边都有 → meta.updated_at 新者胜', function () {
    // 新位置已有较新的记录;旧位置同 hash 较旧 → 旧的被丢弃
    $newDir = storage_path('moo-monitor/runtimes/open');
    @mkdir($newDir, 0755, true);
    file_put_contents($newDir . '/aaaaaaaaaaaa.yaml', Yaml::dump([
        'hash' => 'aaaaaaaaaaaa', 'count' => 9, 'meta' => ['updated_at' => '2026-06-10T00:00:00+00:00'],
    ], 8, 2));
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');

    // 旧位置另一条比新位置新 → 覆盖
    file_put_contents($newDir . '/dddddddddddd.yaml', Yaml::dump([
        'hash' => 'dddddddddddd', 'count' => 1, 'meta' => ['updated_at' => '2026-06-01T00:00:00+00:00'],
    ], 8, 2));
    migrateCmd_seedOld('runtimes', 'open', 'dddddddddddd', '2026-06-11T00:00:00+00:00');

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);

    expect((int) Yaml::parseFile($newDir . '/aaaaaaaaaaaa.yaml')['count'])->toBe(9)  // 新者(已存在)保留
        ->and(Yaml::parseFile($newDir . '/dddddddddddd.yaml')['meta']['updated_at'])->toBe('2026-06-11T00:00:00+00:00');
});

it('游标平移:旧 storage/app/scaffold/cloud-sync.json → 新位置,逐类型取较旧水位', function () {
    // 取小才保守:游标偏旧只是多推一遍(云端幂等),偏新会让旧布局未推记录被增量永久跳过
    $oldDir = storage_path('app/scaffold');
    @mkdir($oldDir, 0755, true);
    file_put_contents($oldDir . '/cloud-sync.json', json_encode([
        'runtimes' => '2026-06-01T00:00:00+00:00', // 旧的较旧 → 采用
        'slow_sql' => '2026-06-10T00:00:00+00:00', // 旧的较新 → 保留新位置的
        'legacy'   => '2026-06-02T00:00:00+00:00', // 新位置没有 → 平移采用
    ]));
    $newDir = storage_path('moo-monitor');
    @mkdir($newDir, 0755, true);
    file_put_contents($newDir . '/cloud-sync.json', json_encode([
        'runtimes' => '2026-06-05T00:00:00+00:00',
        'slow_sql' => '2026-06-03T00:00:00+00:00',
    ]));

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);

    $state = json_decode((string) file_get_contents($newDir . '/cloud-sync.json'), true);
    expect($state['runtimes'])->toBe('2026-06-01T00:00:00+00:00')
        ->and($state['slow_sql'])->toBe('2026-06-03T00:00:00+00:00')
        ->and($state['legacy'])->toBe('2026-06-02T00:00:00+00:00')
        ->and(is_file($oldDir . '/cloud-sync.json'))->toBeFalse(); // 旧游标已删
});

it('dry-run 只报告不动盘', function () {
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');

    $this->artisan('moo:monitor:migrate --dry-run')->assertExitCode(0);

    expect(is_file(base_path('scaffold/runtimes/open/aaaaaaaaaaaa.yaml')))->toBeTrue()  // 原地不动
        ->and(is_file(storage_path('moo-monitor/runtimes/open/aaaaaaaaaaaa.yaml')))->toBeFalse();
});

it('.env 残留旧 SCAFFOLD_* 变量 → 列出改名对照', function () {
    file_put_contents(base_path('.env'), implode("\n", [
        'APP_NAME=demo',
        'SCAFFOLD_CLOUD_TOKEN=abc',
        'SCAFFOLD_SQL_SLOW_ENABLED=true',
        'SCAFFOLD_AUTHOR=charsen', // 非监控变量,不该出现在对照表
    ]));

    // 注:expectsOutputToContain 连续断言会清空输出缓冲,只断言一次最关键的新名;
    // 同一表格行里新旧名成对出现,新名命中即代表对照表已渲染。
    $this->artisan('moo:monitor:migrate')
        ->expectsOutputToContain('MOO_MONITOR_CLOUD_TOKEN')
        ->assertExitCode(0);
});

it('git-sync 时代的 .gitkeep 残留不挡旧目录清理(重跑可补清空壳)', function () {
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');
    // 模拟老宿主:bucket 目录里有 .gitkeep(曾入 git 占位)
    @mkdir(base_path('scaffold/runtimes/resolved'), 0755, true);
    file_put_contents(base_path('scaffold/runtimes/.gitkeep'), '');
    file_put_contents(base_path('scaffold/runtimes/resolved/.gitkeep'), '');

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);

    expect(is_file(storage_path('moo-monitor/runtimes/open/aaaaaaaaaaaa.yaml')))->toBeTrue()
        ->and(is_dir(base_path('scaffold/runtimes')))->toBeFalse(); // .gitkeep 不挡删
});

it('重复跑第二次 → 幂等无动作', function () {
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);
    $this->artisan('moo:monitor:migrate')
        ->expectsOutputToContain('没有发现需要迁移的旧数据')
        ->assertExitCode(0);
});

it('迁移目标目录与游标遵循当前 storage scope', function () {
    config(['moo-monitor.storage_scope' => 'project-a']);
    migrateCmd_seedOld('runtimes', 'open', 'aaaaaaaaaaaa', '2026-06-01T00:00:00+00:00');

    $oldCursorDir = storage_path('app/scaffold');
    @mkdir($oldCursorDir, 0755, true);
    file_put_contents($oldCursorDir . '/cloud-sync.json', json_encode([
        'runtimes' => '2026-06-01T00:00:00+00:00',
    ]));

    $this->artisan('moo:monitor:migrate')->assertExitCode(0);

    expect(is_file(storage_path('moo-monitor/runtimes--project-a/open/aaaaaaaaaaaa.yaml')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/cloud-sync--project-a.json')))->toBeTrue()
        ->and(is_file(storage_path('moo-monitor/runtimes/open/aaaaaaaaaaaa.yaml')))->toBeFalse()
        ->and(is_file(storage_path('moo-monitor/cloud-sync.json')))->toBeFalse();
});
