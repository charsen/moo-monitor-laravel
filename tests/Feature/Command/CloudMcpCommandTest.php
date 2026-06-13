<?php declare(strict_types=1);

use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Command\CloudMcpCommand;

class CloudMcpCommandFakeCloudClient extends CloudClient
{
    public int $fetchRuntimeCalls = 0;

    public int $resolveRuntimeCalls = 0;

    public ?bool $lastWithPayload = null;

    public function __construct() {}

    public function fetchRuntime(string $hash, bool $withPayload = false): array
    {
        $this->fetchRuntimeCalls++;
        $this->lastWithPayload = $withPayload;

        return [
            'ok'     => true,
            'status' => 200,
            'data'   => ['runtime' => ['hash' => $hash, 'markdown' => "runtime {$hash}"]],
            'error'  => null,
        ];
    }

    public function resolveRuntime(string $hash, ?string $note = null, ?string $by = null): array
    {
        $this->resolveRuntimeCalls++;

        return [
            'ok'     => true,
            'status' => 200,
            'data'   => ['runtime' => ['hash' => $hash, 'status' => 'resolved']],
            'error'  => null,
        ];
    }
}

function cloudMcpInvoke(string $method, array $args, CloudMcpCommandFakeCloudClient $cloud): array
{
    $cmd  = new CloudMcpCommand;
    $prop = new ReflectionProperty($cmd, 'cloud');
    $prop->setAccessible(true);
    $prop->setValue($cmd, $cloud);

    $ref = new ReflectionMethod($cmd, $method);
    $ref->setAccessible(true);

    return $ref->invoke($cmd, $args);
}

it('get_runtime 拒绝非法 hash,不打云端', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $res = cloudMcpInvoke('callGetRuntime', ['hash' => 'ABCDEF123456'], $cloud);

    expect($res['isError'])->toBeTrue()
        ->and($res['content'][0]['text'])->toContain('hash 格式非法')
        ->and($cloud->fetchRuntimeCalls)->toBe(0);
});

it('resolve_runtime 拒绝非法 hash,不打云端', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $res = cloudMcpInvoke('callResolveRuntime', ['hash' => '../../etc/passwd'], $cloud);

    expect($res['isError'])->toBeTrue()
        ->and($res['content'][0]['text'])->toContain('hash 格式非法')
        ->and($cloud->resolveRuntimeCalls)->toBe(0);
});

it('get_runtime 合法 hash 仍正常调用云端,with_payload 字符串按布尔解析', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $res = cloudMcpInvoke('callGetRuntime', ['hash' => 'abcdef123456', 'with_payload' => 'false'], $cloud);

    expect($res['isError'])->toBeFalse()
        ->and($cloud->fetchRuntimeCalls)->toBe(1)
        ->and($cloud->lastWithPayload)->toBeFalse();
});
