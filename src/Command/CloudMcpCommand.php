<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2026-06-06
 * @Description: 把本项目在 moo-scaffold-cloud 上的 runtime 错误，以 MCP server 形式
 *               暴露给本仓库里的 AI(Claude Code / Codex 等)——让 AI 直接拉异常、看
 *               完整上下文、修完回写「已解决」，人不必在云端和代码间来回搬运。
 *
 * 这是一个「手写极简」的 stdio MCP server：零额外依赖，只实现 initialize /
 * tools/list / tools/call 三个方法，协议消息按行（newline-delimited JSON-RPC 2.0）
 * 从 STDIN 读、往 STDOUT 写。诊断信息一律走 STDERR —— STDOUT 必须保持纯 JSON-RPC,
 * 否则会污染协议、客户端解析失败。
 *
 * 接入（在任意装了 moo-monitor-laravel 的项目仓库根目录）：
 *   claude mcp add moo-cloud -- php artisan moo:cloud:mcp
 * 之后 AI 即有六个工具：runtime 三件套（list_open_runtimes / get_runtime / resolve_runtime）
 * + 待办三件套（list_open_todos / get_todo / update_todo_status）。
 * 凭据复用 moo-monitor.cloud 已配置的 base_url + token（提报 token 必带 runtimes 能力）。
 */

namespace Mooeen\Monitor\Command;

use Illuminate\Console\Command;
use Mooeen\Monitor\Cloud\CloudClient;
use Throwable;

class CloudMcpCommand extends Command
{
    protected $name = 'moo:cloud:mcp';

    protected $description = '以 MCP server 形式把云端 runtime 错误与待办暴露给本仓库的 AI（拉取 / 查看 / 处理回写）';

    protected $signature = 'moo:cloud:mcp';

    /**
     * 服务端支持的协议版本（新→旧）。客户端请求命中则回显，否则回退到首个（即最新）——
     * 符合 MCP 版本协商：不支持对方所请版本时回最新支持版，由客户端决定是否继续。
     * 含 2025-* 版本，现代客户端（Claude Code / Codex）即可协商到支持 tool annotations 的协议。
     */
    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private CloudClient $cloud;

    public function handle(): int
    {
        @set_time_limit(0);
        $this->cloud = new CloudClient;

        if (! $this->cloud->configured()) {
            // 不退出：仍完成握手，让客户端连上；具体工具调用再返回明确错误。
            fwrite(STDERR, "[moo:cloud:mcp] 警告：moo-monitor.cloud base_url / token 未配置，工具调用将失败。\n");
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            fwrite(STDERR, "[moo:cloud:mcp] 无法打开 STDIN。\n");

            return self::FAILURE;
        }

        // 阻塞式读取，每行一条 JSON-RPC 消息；客户端关闭 stdin(EOF)即退出。
        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $msg = json_decode($line, true);
            if (! is_array($msg)) {
                $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

                continue;
            }

            $this->dispatch($msg);
        }

        return self::SUCCESS;
    }

    /** 路由单条 JSON-RPC 消息。 */
    private function dispatch(array $msg): void
    {
        // 通知（无 id 成员）一律不回任何包（JSON-RPC 2.0：服务端 MUST NOT 回应通知）。
        // 目前没有需要处理副作用的通知，notifications/initialized、cancelled 等直接忽略。
        // 注意 id 可以为 null —— 那是请求，故按 array_key_exists 而非 ?? 判定。
        if (! array_key_exists('id', $msg)) {
            return;
        }

        $id     = $msg['id'];
        $method = (string) ($msg['method'] ?? '');
        $params = (array) ($msg['params'] ?? []);

        try {
            switch ($method) {
                case 'initialize':
                    $this->reply($id, $this->onInitialize($params));
                    break;

                case 'tools/list':
                    $this->reply($id, ['tools' => $this->toolDefinitions()]);
                    break;

                case 'tools/call':
                    $this->reply($id, $this->onToolsCall($params));
                    break;

                case 'ping':
                    $this->reply($id, (object) []);
                    break;

                default:
                    $this->replyError($id, -32601, "Method not found: {$method}");
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[moo:cloud:mcp] 处理 ' . $method . ' 异常：' . $e->getMessage() . "\n");
            $this->replyError($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /** initialize：回应协议版本 + 能力 + 服务端信息。只回显支持的版本，否则回退。 */
    private function onInitialize(array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');
        $version   = in_array($requested, self::SUPPORTED_PROTOCOLS, true)
            ? $requested
            : self::SUPPORTED_PROTOCOLS[0];

        return [
            'protocolVersion' => $version,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'moo-cloud', 'version' => '1.0.0'],
            'instructions'    => $this->serverInstructions(),
        ];
    }

    /** 给 AI 的使用说明（MCP initialize.instructions）：约束「先读后改、改完才闭环」的工作流。 */
    private function serverInstructions(): string
    {
        return implode("\n", [
            '本 server 暴露当前项目在 moo-scaffold-cloud 汇聚的 runtime 运行时错误。工作流：',
            '1. list_open_runtimes 挑要修的异常（默认 open + in_progress;status 可过滤）。hash 是后续操作的唯一键。',
            '2. get_runtime <hash> 拿完整上下文（exc_file:line + 源码片段 + 调用栈）；改 bug 前必看，别凭 list 的摘要猜根因。',
            '3. 改代码并本地验证（跑回归 / 复现路径）通过后，才用 resolve_runtime <hash> 回写「已解决」闭环；未确认修复不要 resolve。',
            '注意：部署窗口瞬态（文件 Failed to open）、切包旧队列（incomplete class）、跨 schema 表缺失等多为环境噪音而非代码 bug——核实后可直接 resolve 并在 note 注明研判，不必改码。',
            '',
            '另暴露团队「待办」（category=bug 是测试/产品经 Chrome 扩展提报的缺陷，带页面 URL / 失败请求 / JS 错误上下文；category=task 是云端手动新建的普通任务）。工作流：',
            '1. list_open_todos 挑要处理的待办（默认 open + in_progress）；id 是后续操作的唯一键。',
            '2. get_todo <id> 拿完整上下文（描述 + 失败请求 + JS 错误 + 时间线）；动手前先 update_todo_status <id> in_progress 认领，避免与他人重复处理。',
            '3. 修完并验证后 update_todo_status <id> done(note 写改了什么)闭环；未确认完成不要标 done。',
        ]);
    }

    /** 三个工具的声明（name + description + JSON Schema 入参）。 */
    private function toolDefinitions(): array
    {
        return [
            [
                'name'        => 'list_open_runtimes',
                'description' => '列出本项目在云端「待处理」的 runtime 运行时错误（默认 open + in_progress，最近优先），用于挑选要修复的异常。返回每条的 hash、异常类、消息、位置、出现次数。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => '返回条数，1–50，默认 20', 'minimum' => 1, 'maximum' => 50],
                        'status' => ['type' => 'string', 'description' => '可选状态过滤', 'enum' => ['open', 'in_progress', 'resolved']],
                    ],
                ],
                'annotations' => [
                    'title'         => '列出云端待处理 runtime 错误',
                    'readOnlyHint'  => true,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name'        => 'get_runtime',
                'description' => '按 hash 取单条 runtime 错误的完整上下文：异常摘要、触发源码片段、项目代码调用栈，以及一段可直接用于分析修复的 markdown。修 bug 前先调它拿到 exc_file:line 与源码。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'hash'         => ['type' => 'string', 'description' => '12 位十六进制错误指纹（来自 list_open_runtimes）'],
                        'with_payload' => ['type' => 'boolean', 'description' => '是否一并返回请求入参 payload（可能含敏感数据），默认 false'],
                    ],
                    'required' => ['hash'],
                ],
                'annotations' => [
                    'title'         => '查看单条 runtime 完整上下文',
                    'readOnlyHint'  => true,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name'        => 'resolve_runtime',
                'description' => '修复并验证后，把该 runtime 错误在云端标记为「已解决」，闭环。仅在确认修复后调用。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'hash'        => ['type' => 'string', 'description' => '12 位十六进制错误指纹'],
                        'note'        => ['type' => 'string', 'description' => '可选：修复说明（如根因 + 改动）'],
                        'resolved_by' => ['type' => 'string', 'description' => '可选：解决人标识，默认由云端按 token 推断'],
                    ],
                    'required' => ['hash'],
                ],
                'annotations' => [
                    'title'           => '标记 runtime 为已解决',
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => true,
                ],
            ],
            [
                'name'        => 'list_open_todos',
                'description' => '列出本项目在云端「可处理」的待办（Chrome 扩展提报的缺陷 + 云端手动新建的任务，默认 open + in_progress，最新优先）。返回每条的 id、标题、类型 category（bug 缺陷 / task 任务）、优先级、状态、页面 URL。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => '返回条数，1–50，默认 20', 'minimum' => 1, 'maximum' => 50],
                        'status' => ['type' => 'string', 'description' => '可选状态过滤', 'enum' => ['open', 'in_progress', 'done']],
                    ],
                ],
                'annotations' => [
                    'title'         => '列出云端可处理待办',
                    'readOnlyHint'  => true,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name'        => 'get_todo',
                'description' => '按 id 取单条待办的完整上下文：标题、描述、页面 URL、失败的网络请求、JS 错误、操作时间线，以及一段可直接用于分析修复的 markdown。处理前先调它，别凭 list 的标题猜。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => '待办 id（来自 list_open_todos）'],
                    ],
                    'required' => ['id'],
                ],
                'annotations' => [
                    'title'         => '查看单条待办完整上下文',
                    'readOnlyHint'  => true,
                    'openWorldHint' => true,
                ],
            ],
            [
                'name'        => 'update_todo_status',
                'description' => '回写待办状态：动手处理前标 in_progress 认领；修完并验证后标 done(note 写改了什么)闭环。仅在确认完成后才标 done。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => ['type' => 'string', 'description' => '待办 id'],
                        'status' => ['type' => 'string', 'description' => '目标状态', 'enum' => ['in_progress', 'done']],
                        'note'   => ['type' => 'string', 'description' => '可选：处理说明（done 时建议写根因 + 改动）'],
                        'by'     => ['type' => 'string', 'description' => '可选：处理人标识，默认由云端按 token 推断'],
                    ],
                    'required' => ['id', 'status'],
                ],
                'annotations' => [
                    'title'           => '回写待办状态（认领 / 完成）',
                    'readOnlyHint'    => false,
                    'destructiveHint' => false,
                    'idempotentHint'  => true,
                    'openWorldHint'   => true,
                ],
            ],
        ];
    }

    /** tools/call：分派到具体工具，统一包装为 MCP content 结果。 */
    private function onToolsCall(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = (array) ($params['arguments'] ?? []);

        return match ($name) {
            'list_open_runtimes' => $this->callListRuntimes($args),
            'get_runtime'        => $this->callGetRuntime($args),
            'resolve_runtime'    => $this->callResolveRuntime($args),
            'list_open_todos'    => $this->callListTodos($args),
            'get_todo'           => $this->callGetTodo($args),
            'update_todo_status' => $this->callUpdateTodoStatus($args),
            default              => $this->toolError("未知工具：{$name}"),
        };
    }

    private function callListRuntimes(array $args): array
    {
        // 与 inputSchema 对齐钳进 [1,50]；模型偶尔无视 schema 传 0 / 超大值，挡在本地少打一次云端。
        $limit  = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 20;
        $status = isset($args['status']) ? (string) $args['status'] : null;
        // 非法 status 别透传：云端白名单不命中会静默回退成 open+in_progress,
        // AI 误以为过滤生效（2026-06-11 修）
        if ($status !== null && ! in_array($status, ['open', 'in_progress', 'resolved'], true)) {
            return $this->toolError("status「{$status}」非法，仅支持 open / in_progress / resolved。");
        }

        $r = $this->cloud->fetchRuntimes($limit, $status);
        if (! $r['ok']) {
            return $this->toolError('拉取失败：' . $r['error']);
        }

        $rows = $r['data']['runtimes'] ?? [];
        if ($rows === []) {
            $scope = $status !== null ? "「{$status}」状态的" : '待处理的';

            return $this->toolText("云端没有{$scope} runtime 错误。");
        }

        // 提示返回条数 + 是否触顶，让 AI 判断要不要收窄 status 或还有更多未取（云端无分页游标）。
        $hint = count($rows) >= $limit ? "（已达上限 {$limit} 条，可能还有更多；可用 status 收窄）" : '';

        return $this->toolText('共 ' . count($rows) . " 条{$hint}:\n" . $this->jsonText($rows));
    }

    private function callGetRuntime(array $args): array
    {
        $hash = trim((string) ($args['hash'] ?? ''));
        if ($hash === '') {
            return $this->toolError('缺少 hash。');
        }
        if (! $this->isRuntimeHash($hash)) {
            return $this->toolError('hash 格式非法，必须是 12 位小写十六进制。');
        }

        // 模型常把 boolean 送成字符串（"false"/"no"），(bool) 强转会变 true → 敏感 payload
        // 意外带出；filter_var 按语义解析，解析不了的怪值按 false 安全侧处理（2026-06-11 修）
        $withPayload = filter_var($args['with_payload'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        $r           = $this->cloud->fetchRuntime($hash, $withPayload);
        if (! $r['ok']) {
            return $this->toolError('获取失败：' . $r['error']);
        }

        $rt = $r['data']['runtime'] ?? [];
        if ($rt === []) {
            return $this->toolError("未找到 hash「{$hash}」对应的 runtime（可能 hash 有误或已被清理）。先用 list_open_runtimes 取最新 hash。");
        }

        // markdown 已是 toAiMarkdown 产出的「可直接喂 AI」文本；附 payload 时一并给出。
        $text = (string) ($rt['markdown'] ?? $this->jsonText($rt));
        if (! empty($rt['payload'])) {
            $text .= "\n\n## 请求入参（payload）\n```json\n" . $this->jsonText($rt['payload']) . "\n```";
        }

        return $this->toolText($text);
    }

    private function callResolveRuntime(array $args): array
    {
        $hash = trim((string) ($args['hash'] ?? ''));
        if ($hash === '') {
            return $this->toolError('缺少 hash。');
        }
        if (! $this->isRuntimeHash($hash)) {
            return $this->toolError('hash 格式非法，必须是 12 位小写十六进制。');
        }

        $r = $this->cloud->resolveRuntime(
            $hash,
            isset($args['note']) ? (string) $args['note'] : null,
            isset($args['resolved_by']) ? (string) $args['resolved_by'] : null,
        );
        if (! $r['ok']) {
            return $this->toolError('回写失败：' . $r['error']);
        }

        // 云端幂等：已被解决时保留既有记录不重写 —— 如实告知，别让 AI 误以为自己的 note 已落库
        if (! empty($r['data']['already_resolved'])) {
            $byWho = (string) ($r['data']['runtime']['resolved_by'] ?? '');

            return $this->toolText("runtime {$hash} 此前已被解决" . ($byWho !== '' ? "（解决人：{$byWho}）" : '') . '，既有解决记录保留，本次未改动。');
        }

        return $this->toolText("已将 runtime {$hash} 标记为「已解决」。");
    }

    private function callListTodos(array $args): array
    {
        $limit  = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 20;
        $status = isset($args['status']) ? (string) $args['status'] : null;
        // 同 list_open_runtimes：非法 status 本地拦下，不让云端静默回退误导
        if ($status !== null && ! in_array($status, ['open', 'in_progress', 'done'], true)) {
            return $this->toolError("status「{$status}」非法，仅支持 open / in_progress / done。");
        }

        $r = $this->cloud->fetchTodos($limit, $status);
        if (! $r['ok']) {
            return $this->toolError('拉取失败：' . $r['error']);
        }

        $rows = $r['data']['todos'] ?? [];
        if ($rows === []) {
            $scope = $status !== null ? "「{$status}」状态的" : '可处理的';

            return $this->toolText("云端没有{$scope}待办。");
        }

        $hint = count($rows) >= $limit ? "（已达上限 {$limit} 条，可能还有更多；可用 status 收窄）" : '';

        return $this->toolText('共 ' . count($rows) . " 条{$hint}:\n" . $this->jsonText($rows));
    }

    private function callGetTodo(array $args): array
    {
        $id = trim((string) ($args['id'] ?? ''));
        if ($id === '') {
            return $this->toolError('缺少 id。');
        }

        $r = $this->cloud->fetchTodo($id);
        if (! $r['ok']) {
            return $this->toolError('获取失败：' . $r['error']);
        }

        $todo = $r['data']['todo'] ?? [];
        if ($todo === []) {
            return $this->toolError("未找到 id「{$id}」对应的待办（可能 id 有误或已删除）。先用 list_open_todos 取最新 id。");
        }

        // markdown 是 toAiMarkdown 产出的「可直接喂 AI」文本；时间线/截图数附在其后供参考。
        $text     = (string) ($todo['markdown'] ?? $this->jsonText($todo));
        $category = (string) ($todo['category'] ?? '');
        // category 是云端 2026-06-22 新增字段（enum bug|task，缺省 bug）；markdown 标题已含分类，
        // 这里在元信息块也显式列出，与 状态/优先级 对齐。未知/缺失值原样透传，不臆断为 Bug。
        $kind = match ($category) {
            'task'  => '任务',
            'bug'   => 'Bug（缺陷）',
            ''      => '—',
            default => $category,
        };
        $extra = [
            '类型'      => $kind,
            '状态'      => $todo['status']   ?? '',
            '优先级'    => $todo['priority'] ?? '',
            '截图/录屏' => ($todo['attachments_count'] ?? 0) . ' 个（二进制，详见云端 UI）',
        ];
        $text .= "\n\n## 元信息\n" . $this->jsonText($extra);
        if (! empty($todo['events'])) {
            $text .= "\n\n## 操作时间线\n" . $this->jsonText($todo['events']);
        }

        return $this->toolText($text);
    }

    private function callUpdateTodoStatus(array $args): array
    {
        $id     = trim((string) ($args['id'] ?? ''));
        $status = trim((string) ($args['status'] ?? ''));
        if ($id === '') {
            return $this->toolError('缺少 id。');
        }
        if (! in_array($status, ['in_progress', 'done'], true)) {
            return $this->toolError('status 仅支持 in_progress / done。');
        }

        $r = $this->cloud->updateTodoStatus(
            $id,
            $status,
            isset($args['note']) ? (string) $args['note'] : null,
            isset($args['by']) ? (string) $args['by'] : null,
        );
        if (! $r['ok']) {
            return $this->toolError('回写失败：' . $r['error']);
        }

        if (! empty($r['data']['already_done'])) {
            return $this->toolText("待办 {$id} 此前已是「完成」状态，本次未改动（既有完成记录保留）。");
        }

        if (! empty($r['data']['already_in_progress'])) {
            return $this->toolText("待办 {$id} 已是「处理中」—— 可能已被他人认领，动手前用 get_todo 看时间线确认，避免重复处理。");
        }

        $label = $status === 'done' ? '已完成' : '处理中（已认领）';

        return $this->toolText("已将待办 {$id} 标记为「{$label}」。");
    }

    // ---- MCP / JSON-RPC 低层 ----------------------------------------------

    /** 成功响应。 */
    private function reply(mixed $id, mixed $result): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /** 错误响应。 */
    private function replyError(mixed $id, int $code, string $message): void
    {
        $this->send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** 一条 JSON-RPC 消息写到 STDOUT（单行 + 换行）。 */
    private function send(array $msg): void
    {
        fwrite(STDOUT, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }

    /** MCP 工具成功结果（纯文本内容）。 */
    private function toolText(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false];
    }

    /** MCP 工具错误结果（isError=true，让模型看到失败原因而非协议层报错）。 */
    private function toolError(string $text): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => true];
    }

    private function jsonText(mixed $v): string
    {
        return (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isRuntimeHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{12}$/', $hash) === 1;
    }
}
