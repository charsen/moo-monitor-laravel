<?php declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Mooeen\MonitorLaravel\Cloud\CloudClient;
use Mooeen\MonitorLaravel\Cloud\CloudSync;
use Mooeen\MonitorLaravel\Command\CloudToolset;
use Symfony\Component\Yaml\Yaml;

it('镜像快照 fixture 的 previous / connection 经过 push 和 MCP 后仍保留', function () {
    $fixture         = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/cloud-monitor-snapshot-contract.json'), true, flags: JSON_THROW_ON_ERROR);
    $originalStorage = storage_path();
    $sandbox         = sys_get_temp_dir() . '/monitor_snapshot_contract_' . uniqid();
    mkdir($sandbox, 0755, true);
    app()->useStoragePath($sandbox);
    config([
        'moo-monitor.storage_scope'  => 'off',
        'moo-monitor.cloud.enabled'  => true,
        'moo-monitor.cloud.base_url' => 'https://cloud.test',
        'moo-monitor.cloud.token'    => 'moo_fixture_token',
    ]);
    Http::fake([
        'cloud.test/api/v1/runtimes/intake'     => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0]),
        'cloud.test/api/v1/slow-queries/intake' => Http::response(['ok' => true, 'saved' => 1, 'filtered' => 0, 'skipped' => 0]),
        'cloud.test/api/v1/runtimes/get'        => Http::response($fixture['runtime_read']),
    ]);
    try {
        foreach ([['runtimes', 'runtimes', 'runtime_record'], ['slow_sql', 'sql-slows', 'slow_query_record']] as [$type, $path, $key]) {
            $record = $fixture[$key];
            $dir    = storage_path('moo-monitor/' . $path . '/open');
            mkdir($dir, 0755, true);
            file_put_contents($dir . '/' . $record['hash'] . '.yaml', Yaml::dump($record, 10));
            expect((new CloudSync($sandbox . '/cursor.json'))->sync($type)['ok'])->toBeTrue();
            Http::assertSent(fn ($request) => ($request['records'][0] ?? null) === $record);
        }
        $result = (new CloudToolset(new CloudClient(config('moo-monitor.cloud'))))->call('get_runtime', ['hash' => $fixture['runtime_record']['hash']]);
        expect($result['structuredContent']['runtime']['exception']['previous'])->toBe($fixture['runtime_record']['exception']['previous'])
            ->and($result['content'][0]['text'])->toContain('missing relation');
    } finally {
        app()->useStoragePath($originalStorage);
        $delete = function (string $path) use (&$delete): void {
            if (is_dir($path)) {
                foreach (scandir($path) as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $delete($path . '/' . $file);
                    }
                }
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $delete($sandbox);
    }
});
