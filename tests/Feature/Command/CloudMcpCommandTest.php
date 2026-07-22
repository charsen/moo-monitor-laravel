<?php declare(strict_types=1);

use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Command\CloudToolset;
use Mooeen\Monitor\Command\McpLoop;

class CloudMcpCommandFakeCloudClient extends CloudClient
{
    public int $fetchRuntimesCalls = 0;

    public int $fetchRuntimeCalls = 0;

    public int $resolveRuntimeCalls = 0;

    public int $fetchTodosCalls = 0;

    public int $fetchTodoCalls = 0;

    public int $updateTodoStatusCalls = 0;

    public ?bool $lastWithPayload = null;

    /** @var array{limit:int,status:?string,offset:int}|null */
    public ?array $lastRuntimeList = null;

    /** @var array{limit:int,status:?string,offset:int}|null */
    public ?array $lastTodoList = null;

    /** @var array<string,mixed> */
    public array $runtimeListData = [
        'offset'      => 0,
        'has_more'    => false,
        'next_offset' => null,
        'runtimes'    => [['hash' => 'abcdef123456']],
    ];

    /** @var array<string,mixed> */
    public array $todoListData = [
        'offset'      => 0,
        'has_more'    => false,
        'next_offset' => null,
        'todos'       => [['id' => '01ky1521knanpadjkys0s7wzkr']],
    ];

    /** 受测控制：fetchTodo 返回的 category 值，以及是否干脆不带 category 键。 */
    public ?string $todoCategory = 'bug';

    public bool $omitCategory = false;

    public function __construct() {}

    public function fetchRuntimes(int $limit = 20, ?string $status = null, int $offset = 0): array
    {
        $this->fetchRuntimesCalls++;
        $this->lastRuntimeList = compact('limit', 'status', 'offset');

        return ['ok' => true, 'status' => 200, 'data' => $this->runtimeListData, 'error' => null];
    }

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

    public function fetchTodos(int $limit = 20, ?string $status = null, int $offset = 0): array
    {
        $this->fetchTodosCalls++;
        $this->lastTodoList = compact('limit', 'status', 'offset');

        return ['ok' => true, 'status' => 200, 'data' => $this->todoListData, 'error' => null];
    }

    public function fetchTodo(string $id): array
    {
        $this->fetchTodoCalls++;
        $todo = [
            'id'       => $id,
            'status'   => 'open',
            'priority' => 'high',
            'markdown' => "todo {$id}",
        ];
        if (! $this->omitCategory) {
            $todo['category'] = $this->todoCategory;
        }

        return [
            'ok'     => true,
            'status' => 200,
            'data'   => ['todo' => $todo],
            'error'  => null,
        ];
    }

    public function updateTodoStatus(string $id, string $status, ?string $note = null, ?string $by = null): array
    {
        $this->updateTodoStatusCalls++;

        return [
            'ok'     => true,
            'status' => 200,
            'data'   => ['todo' => ['id' => $id, 'status' => $status]],
            'error'  => null,
        ];
    }
}

function cloudMcpInvoke(string $method, array $args, CloudMcpCommandFakeCloudClient $cloud): array
{
    // P5：工具 handler 已从命令拆到 CloudToolset，直接在 toolset 上反射调用。
    $toolset = new CloudToolset($cloud);
    $ref     = new ReflectionMethod($toolset, $method);
    $ref->setAccessible(true);

    return $ref->invoke($toolset, $args);
}

/** @return array{responses:array<int,array<string,mixed>>,stderr:string} */
function cloudMcpRun(array $messages, CloudMcpCommandFakeCloudClient $cloud): array
{
    $stdin  = fopen('php://temp', 'r+');
    $stdout = fopen('php://temp', 'r+');
    $stderr = fopen('php://temp', 'r+');
    foreach ($messages as $message) {
        fwrite($stdin, is_string($message) ? $message . "\n" : json_encode($message) . "\n");
    }
    rewind($stdin);

    (new McpLoop(new CloudToolset($cloud), $stdout, $stderr))->run($stdin);

    rewind($stdout);
    rewind($stderr);
    $lines = array_values(array_filter(explode("\n", trim((string) stream_get_contents($stdout)))));

    return [
        'responses' => array_map(fn (string $line) => (array) json_decode($line, true), $lines),
        'stderr'    => (string) stream_get_contents($stderr),
    ];
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

it('MCP 待办说明与工具定义覆盖四种 category', function () {
    $toolset = new CloudToolset(new CloudMcpCommandFakeCloudClient);
    $text    = $toolset->instructions() . ' ' . json_encode($toolset->definitions(), JSON_UNESCAPED_UNICODE);

    expect($text)
        ->toContain('bug')
        ->toContain('frontend_bug')
        ->toContain('backend_bug')
        ->toContain('task');
});

it('list 工具声明 offset 并透传分页参数与下一页提示', function () {
    $cloud                  = new CloudMcpCommandFakeCloudClient;
    $cloud->runtimeListData = [
        'offset'      => 50,
        'has_more'    => true,
        'next_offset' => 57,
        'runtimes'    => [['hash' => 'abcdef123456']],
    ];
    $cloud->todoListData = [
        'offset'      => 10,
        'has_more'    => true,
        'next_offset' => 15,
        'todos'       => [['id' => '01ky1521knanpadjkys0s7wzkr']],
    ];

    $runtimes    = cloudMcpInvoke('callListRuntimes', ['limit' => 7, 'status' => 'resolved', 'offset' => 50], $cloud);
    $todos       = cloudMcpInvoke('callListTodos', ['limit' => 5, 'status' => 'done', 'offset' => 10], $cloud);
    $definitions = (new CloudToolset($cloud))->definitions();

    expect($definitions[0]['inputSchema']['properties']['offset']['minimum'])->toBe(0)
        ->and($definitions[3]['inputSchema']['properties']['offset']['minimum'])->toBe(0)
        ->and($cloud->lastRuntimeList)->toBe(['limit' => 7, 'status' => 'resolved', 'offset' => 50])
        ->and($cloud->lastTodoList)->toBe(['limit' => 5, 'status' => 'done', 'offset' => 10])
        ->and($runtimes['content'][0]['text'])->toContain('offset 50，has_more=true')
        ->and($runtimes['content'][0]['text'])->toContain('limit=7、status=resolved，传 offset=57')
        ->and($todos['content'][0]['text'])->toContain('offset 10，has_more=true')
        ->and($todos['content'][0]['text'])->toContain('limit=5、status=done，传 offset=15');
});

it('list 工具把负 offset 钳为零并拒绝畸形类型', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $clamped = cloudMcpInvoke('callListRuntimes', ['offset' => -9], $cloud);
    $invalid = cloudMcpInvoke('callListTodos', ['offset' => '10'], $cloud);

    expect($clamped['isError'])->toBeFalse()
        ->and($cloud->lastRuntimeList['offset'])->toBe(0)
        ->and($invalid['isError'])->toBeTrue()
        ->and($invalid['content'][0]['text'])->toContain('offset 必须是整数')
        ->and($cloud->fetchTodosCalls)->toBe(0);
});

it('后续页缺少 Cloud 分页回执时 fail closed 避免重复第一页', function () {
    $cloud                  = new CloudMcpCommandFakeCloudClient;
    $cloud->runtimeListData = ['runtimes' => [['hash' => 'abcdef123456']]];

    $res = cloudMcpInvoke('callListRuntimes', ['offset' => 50], $cloud);

    expect($res['isError'])->toBeTrue()
        ->and($res['content'][0]['text'])->toContain('可能仍是旧版本且忽略了 offset');
});

it('Cloud 只返回部分分页字段时拒绝采信', function () {
    $cloud                  = new CloudMcpCommandFakeCloudClient;
    $cloud->runtimeListData = [
        'offset'   => 0,
        'has_more' => false,
        'runtimes' => [['hash' => 'abcdef123456']],
    ];

    $res = cloudMcpInvoke('callListRuntimes', [], $cloud);

    expect($res['isError'])->toBeTrue()
        ->and($res['content'][0]['text'])->toContain('分页字段不完整');
});

it('get_todo 元信息按 category 渲染「类型」标签', function (?string $category, bool $omit, string $expected) {
    $cloud               = new CloudMcpCommandFakeCloudClient;
    $cloud->todoCategory = $category;
    $cloud->omitCategory = $omit;

    $res = cloudMcpInvoke('callGetTodo', ['id' => '01ky1521knanpadjkys0s7wzkr'], $cloud);

    expect($res['isError'])->toBeFalse()
        ->and($res['content'][0]['text'])->toContain('"类型"')
        ->and($res['content'][0]['text'])->toContain($expected);
})->with([
    'bug → Bug（待分类）'     => ['bug', false, 'Bug（待分类）'],
    'frontend_bug → 前端缺陷' => ['frontend_bug', false, '前端 Bug（缺陷）'],
    'backend_bug → 后端缺陷'  => ['backend_bug', false, '后端 Bug（缺陷）'],
    'task → 任务'             => ['task', false, '任务'],
    '未知值原样透传'          => ['weird', false, 'weird'],
    'category 缺失 → —'       => [null, true, '—'],
]);

it('get_todo 与 update_todo_status 拒绝非法 id,不打云端', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $get    = cloudMcpInvoke('callGetTodo', ['id' => '../../etc/passwd'], $cloud);
    $update = cloudMcpInvoke('callUpdateTodoStatus', ['id' => 'short', 'status' => 'done'], $cloud);

    expect($get['isError'])->toBeTrue()
        ->and($update['isError'])->toBeTrue()
        ->and($cloud->fetchTodoCalls)->toBe(0)
        ->and($cloud->updateTodoStatusCalls)->toBe(0);
});

it('工具参数畸形类型在本地返回明确错误,不触发字符串转换 warning', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;

    $hash   = cloudMcpInvoke('callGetRuntime', ['hash' => ['abcdef123456']], $cloud);
    $todo   = cloudMcpInvoke('callGetTodo', ['id' => ['01ky1521knanpadjkys0s7wzkr']], $cloud);
    $status = cloudMcpInvoke('callUpdateTodoStatus', [
        'id' => '01ky1521knanpadjkys0s7wzkr', 'status' => 'done', 'note' => ['bad'],
    ], $cloud);
    $list   = cloudMcpInvoke('callListTodos', ['status' => ['open']], $cloud);
    $offset = cloudMcpInvoke('callListRuntimes', ['offset' => ['bad']], $cloud);

    expect($hash['content'][0]['text'])->toContain('hash 必须是字符串')
        ->and($todo['content'][0]['text'])->toContain('id 必须是字符串')
        ->and($status['content'][0]['text'])->toContain('note 必须是字符串')
        ->and($list['content'][0]['text'])->toContain('status 必须是字符串')
        ->and($offset['content'][0]['text'])->toContain('offset 必须是整数')
        ->and($cloud->fetchRuntimesCalls)->toBe(0)
        ->and($cloud->fetchRuntimeCalls)->toBe(0)
        ->and($cloud->fetchTodoCalls)->toBe(0)
        ->and($cloud->updateTodoStatusCalls)->toBe(0);
});

it('McpLoop 协商协议、忽略 notification 并返回 tools/list 与 ping', function () {
    $run = cloudMcpRun([
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'method' => 'unknown-notification'],
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'],
    ], new CloudMcpCommandFakeCloudClient);

    expect($run['responses'])->toHaveCount(3)
        ->and($run['responses'][0]['result']['protocolVersion'])->toBe('2025-06-18')
        ->and($run['responses'][0]['result']['instructions'])->toContain('frontend_bug')
        ->and($run['responses'][1]['result']['tools'])->toHaveCount(6)
        ->and($run['responses'][2]['result'])->toBe([])
        ->and($run['stderr'])->toBe('');
});

it('McpLoop 对解析错误、非法请求和非法参数返回标准 JSON-RPC 错误', function () {
    $run = cloudMcpRun([
        '{',
        '[]',
        '{}',
        ['jsonrpc' => '1.0', 'method' => 'ping'],
        ['id'      => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping', 'params' => 'bad'],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => ['get_todo']]],
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'unknown'],
        ['jsonrpc' => '2.0', 'id' => ['bad'], 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'initialize', 'params' => ['protocolVersion' => ['2025-06-18']]],
    ], new CloudMcpCommandFakeCloudClient);

    expect(array_column(array_column($run['responses'], 'error'), 'code'))
        ->toBe([-32700, -32600, -32600, -32600, -32600, -32602, -32602, -32601, -32600, -32602])
        ->and(array_column($run['responses'], 'id'))->toBe([null, null, null, null, 1, 2, 3, 4, null, 5])
        ->and($run['stderr'])->toBe('');
});

it('McpLoop tools/call 能执行合法 get_todo', function () {
    $cloud = new CloudMcpCommandFakeCloudClient;
    $run   = cloudMcpRun([[
        'jsonrpc' => '2.0',
        'id'      => 'todo-1',
        'method'  => 'tools/call',
        'params'  => ['name' => 'get_todo', 'arguments' => ['id' => '01ky1521knanpadjkys0s7wzkr']],
    ]], $cloud);

    expect($run['responses'][0]['id'])->toBe('todo-1')
        ->and($run['responses'][0]['result']['isError'])->toBeFalse()
        ->and($cloud->fetchTodoCalls)->toBe(1);
});
