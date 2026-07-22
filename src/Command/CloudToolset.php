<?php declare(strict_types=1);

namespace Mooeen\Monitor\Command;

use Mooeen\Monitor\Cloud\CloudClient;

/**
 * moo:cloud:mcp 的工具层：六个工具的定义（name + description + JSON Schema 入参）与 handler，
 * 以及给 AI 的 initialize.instructions。从 CloudMcpCommand 拆出（P5）—— 命令类只剩装配，
 * 传输层 McpLoop 负责 stdin 循环 + JSON-RPC 编解码，本类只管「有哪些工具、每个工具怎么执行」。
 *
 * runtime 三件套（list_open_runtimes / get_runtime / resolve_runtime）+ 待办三件套
 * （list_open_todos / get_todo / update_todo_status）。凭据复用 moo-monitor.cloud 的 base_url + token。
 */
class CloudToolset
{
    public function __construct(private CloudClient $cloud) {}

    /** 给 AI 的使用说明（MCP initialize.instructions）：约束「先读后改、改完才闭环」的工作流。 */
    public function instructions(): string
    {
        return implode("\n", [
            '本 server 暴露当前项目在 moo-scaffold-cloud 汇聚的 runtime 运行时错误。工作流：',
            '1. list_open_runtimes 挑要修的异常（默认 open + in_progress；status 可过滤；has_more=true 时按 next_offset 翻页）。hash 是后续操作的唯一键。',
            '2. get_runtime <hash> 拿完整上下文（exc_file:line + 源码片段 + 调用栈）；改 bug 前必看，别凭 list 的摘要猜根因。',
            '3. 改代码并本地验证（跑回归 / 复现路径）通过后，才用 resolve_runtime <hash> 回写「已解决」闭环；未确认修复不要 resolve。',
            '注意：部署窗口瞬态（文件 Failed to open）、切包旧队列（incomplete class）、跨 schema 表缺失等多为环境噪音而非代码 bug——核实后可直接 resolve 并在 note 注明研判，不必改码。',
            '',
            '另暴露团队「待办」：category=bug 是待分类缺陷，frontend_bug / backend_bug 是已归类的前端 / 后端缺陷，task 是普通任务；缺陷通常带页面 URL / 失败请求 / JS 错误上下文。工作流：',
            '1. list_open_todos 挑要处理的待办（默认 open + in_progress；has_more=true 时按 next_offset 翻页）；id 是后续操作的唯一键。',
            '2. get_todo <id> 拿完整上下文（描述 + 失败请求 + JS 错误 + 时间线）；动手前先 update_todo_status <id> in_progress 认领，避免与他人重复处理。',
            '3. 修完并验证后 update_todo_status <id> done(note 写改了什么)闭环；未确认完成不要标 done。',
        ]);
    }

    /** 六个工具的声明（name + description + JSON Schema 入参）。 */
    public function definitions(): array
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
                        'offset' => ['type' => 'integer', 'description' => '分页偏移量，默认 0；下一页使用上次返回的 next_offset', 'minimum' => 0],
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
                'description' => '列出本项目在云端「可处理」的待办（默认 open + in_progress，最新优先）。返回每条的 id、标题、类型 category（bug 待分类缺陷 / frontend_bug 前端缺陷 / backend_bug 后端缺陷 / task 任务）、优先级、状态、页面 URL。',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'limit'  => ['type' => 'integer', 'description' => '返回条数，1–50，默认 20', 'minimum' => 1, 'maximum' => 50],
                        'status' => ['type' => 'string', 'description' => '可选状态过滤', 'enum' => ['open', 'in_progress', 'done']],
                        'offset' => ['type' => 'integer', 'description' => '分页偏移量，默认 0；下一页使用上次返回的 next_offset', 'minimum' => 0],
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
    public function call(string $name, array $args): array
    {
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
        if (array_key_exists('limit', $args) && ! is_int($args['limit'])) {
            return $this->toolError('limit 必须是整数。');
        }
        if (array_key_exists('status', $args) && ! is_string($args['status'])) {
            return $this->toolError('status 必须是字符串。');
        }
        if (array_key_exists('offset', $args) && ! is_int($args['offset'])) {
            return $this->toolError('offset 必须是整数。');
        }
        $limit  = isset($args['limit']) ? max(1, min(50, $args['limit'])) : 20;
        $status = $args['status'] ?? null;
        $offset = isset($args['offset']) ? max(0, $args['offset']) : 0;
        // 非法 status 别透传：云端白名单不命中会静默回退成 open+in_progress,
        // AI 误以为过滤生效（2026-06-11 修）
        if ($status !== null && ! in_array($status, ['open', 'in_progress', 'resolved'], true)) {
            return $this->toolError("status「{$status}」非法，仅支持 open / in_progress / resolved。");
        }

        $r = $this->cloud->fetchRuntimes($limit, $status, $offset);
        if (! $r['ok']) {
            return $this->toolError('拉取失败：' . $r['error']);
        }

        $page = $this->pagination($r['data'] ?? [], $offset);
        if ($page['error'] !== null) {
            return $this->toolError('分页失败：' . $page['error']);
        }

        $rows = $r['data']['runtimes'] ?? [];
        if ($rows === []) {
            $scope = $status !== null ? "「{$status}」状态的" : '待处理的';

            return $this->toolText("云端没有{$scope} runtime 错误。");
        }

        $hint = $this->paginationHint($page, count($rows), $limit, $status);

        return $this->toolText('共 ' . count($rows) . " 条{$hint}:\n" . $this->jsonText($rows));
    }

    private function callGetRuntime(array $args): array
    {
        if (array_key_exists('hash', $args) && ! is_string($args['hash'])) {
            return $this->toolError('hash 必须是字符串。');
        }
        $hash = trim($args['hash'] ?? '');
        if ($hash === '') {
            return $this->toolError('缺少 hash。');
        }
        if (! $this->isRuntimeHash($hash)) {
            return $this->toolError('hash 格式非法，必须是 12 位小写十六进制。');
        }

        // 模型常把 boolean 送成字符串（"false"/"no"），(bool) 强转会变 true → 敏感 payload
        // 意外带出；filter_var 按语义解析，解析不了的怪值按 false 安全侧处理（2026-06-11 修）
        if (array_key_exists('with_payload', $args) && ! is_bool($args['with_payload']) && ! is_string($args['with_payload'])) {
            return $this->toolError('with_payload 必须是布尔值。');
        }
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
        if (array_key_exists('hash', $args) && ! is_string($args['hash'])) {
            return $this->toolError('hash 必须是字符串。');
        }
        foreach (['note', 'resolved_by'] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null && ! is_string($args[$key])) {
                return $this->toolError("{$key} 必须是字符串。");
            }
        }
        $hash = trim($args['hash'] ?? '');
        if ($hash === '') {
            return $this->toolError('缺少 hash。');
        }
        if (! $this->isRuntimeHash($hash)) {
            return $this->toolError('hash 格式非法，必须是 12 位小写十六进制。');
        }

        $r = $this->cloud->resolveRuntime(
            $hash,
            $args['note']        ?? null,
            $args['resolved_by'] ?? null,
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
        if (array_key_exists('limit', $args) && ! is_int($args['limit'])) {
            return $this->toolError('limit 必须是整数。');
        }
        if (array_key_exists('status', $args) && ! is_string($args['status'])) {
            return $this->toolError('status 必须是字符串。');
        }
        if (array_key_exists('offset', $args) && ! is_int($args['offset'])) {
            return $this->toolError('offset 必须是整数。');
        }
        $limit  = isset($args['limit']) ? max(1, min(50, $args['limit'])) : 20;
        $status = $args['status'] ?? null;
        $offset = isset($args['offset']) ? max(0, $args['offset']) : 0;
        // 同 list_open_runtimes：非法 status 本地拦下，不让云端静默回退误导
        if ($status !== null && ! in_array($status, ['open', 'in_progress', 'done'], true)) {
            return $this->toolError("status「{$status}」非法，仅支持 open / in_progress / done。");
        }

        $r = $this->cloud->fetchTodos($limit, $status, $offset);
        if (! $r['ok']) {
            return $this->toolError('拉取失败：' . $r['error']);
        }

        $page = $this->pagination($r['data'] ?? [], $offset);
        if ($page['error'] !== null) {
            return $this->toolError('分页失败：' . $page['error']);
        }

        $rows = $r['data']['todos'] ?? [];
        if ($rows === []) {
            $scope = $status !== null ? "「{$status}」状态的" : '可处理的';

            return $this->toolText("云端没有{$scope}待办。");
        }

        $hint = $this->paginationHint($page, count($rows), $limit, $status);

        return $this->toolText('共 ' . count($rows) . " 条{$hint}:\n" . $this->jsonText($rows));
    }

    private function callGetTodo(array $args): array
    {
        if (array_key_exists('id', $args) && ! is_string($args['id'])) {
            return $this->toolError('id 必须是字符串。');
        }
        $id = trim($args['id'] ?? '');
        if ($id === '') {
            return $this->toolError('缺少 id。');
        }
        if (! $this->isTodoId($id)) {
            return $this->toolError('id 格式非法，必须是 20–32 位字母或数字。');
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
        // category 是云端分类字段；markdown 标题已含分类，
        // 这里在元信息块也显式列出，与 状态/优先级 对齐。未知/缺失值原样透传，不臆断为 Bug。
        $kind = match ($category) {
            'task'         => '任务',
            'bug'          => 'Bug（待分类）',
            'frontend_bug' => '前端 Bug（缺陷）',
            'backend_bug'  => '后端 Bug（缺陷）',
            ''             => '—',
            default        => $category,
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
        foreach (['id', 'status', 'note', 'by'] as $key) {
            if (array_key_exists($key, $args) && $args[$key] !== null && ! is_string($args[$key])) {
                return $this->toolError("{$key} 必须是字符串。");
            }
        }
        $id     = trim($args['id'] ?? '');
        $status = trim($args['status'] ?? '');
        if ($id === '') {
            return $this->toolError('缺少 id。');
        }
        if (! $this->isTodoId($id)) {
            return $this->toolError('id 格式非法，必须是 20–32 位字母或数字。');
        }
        if (! in_array($status, ['in_progress', 'done'], true)) {
            return $this->toolError('status 仅支持 in_progress / done。');
        }

        $r = $this->cloud->updateTodoStatus(
            $id,
            $status,
            $args['note'] ?? null,
            $args['by']   ?? null,
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

    /**
     * 严格读取 Cloud 的 offset 回执。后续页没有回执时 fail-closed，避免旧 Cloud 忽略 offset
     * 后让 AI 永久重复第一页；第一页仍兼容尚未返回分页字段的旧 Cloud。
     *
     * @param array<string,mixed> $data
     *
     * @return array{supported:bool,offset:int,has_more:bool,next_offset:?int,error:?string}
     */
    private function pagination(array $data, int $requestedOffset): array
    {
        $keys        = ['offset', 'has_more', 'next_offset'];
        $hasAnyField = false;
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $hasAnyField = true;
                break;
            }
        }

        if (! $hasAnyField) {
            return [
                'supported'   => false,
                'offset'      => $requestedOffset,
                'has_more'    => false,
                'next_offset' => null,
                'error'       => $requestedOffset > 0 ? 'Cloud 未返回 offset / has_more / next_offset，可能仍是旧版本且忽略了 offset；请先升级 Cloud。' : null,
            ];
        }
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                return [
                    'supported'   => true,
                    'offset'      => $requestedOffset,
                    'has_more'    => false,
                    'next_offset' => null,
                    'error'       => 'Cloud 返回的 offset / has_more / next_offset 分页字段不完整。',
                ];
            }
        }

        $offset     = $data['offset']      ?? null;
        $hasMore    = $data['has_more']    ?? null;
        $nextOffset = $data['next_offset'] ?? null;
        if (! is_int($offset) || $offset !== $requestedOffset || ! is_bool($hasMore)) {
            return [
                'supported'   => true,
                'offset'      => $requestedOffset,
                'has_more'    => false,
                'next_offset' => null,
                'error'       => 'Cloud 返回的 offset / has_more 与请求不一致。',
            ];
        }
        if (($hasMore && (! is_int($nextOffset) || $nextOffset <= $offset))
            || (! $hasMore && $nextOffset !== null)) {
            return [
                'supported'   => true,
                'offset'      => $offset,
                'has_more'    => $hasMore,
                'next_offset' => null,
                'error'       => 'Cloud 返回的 next_offset 非法。',
            ];
        }

        return [
            'supported'   => true,
            'offset'      => $offset,
            'has_more'    => $hasMore,
            'next_offset' => $nextOffset,
            'error'       => null,
        ];
    }

    /** @param array{supported:bool,offset:int,has_more:bool,next_offset:?int,error:?string} $page */
    private function paginationHint(array $page, int $count, int $limit, ?string $status): string
    {
        if (! $page['supported']) {
            return $count >= $limit ? "（has_more=unknown；已达上限 {$limit} 条，可能还有更多；当前 Cloud 未提供分页元数据）" : '';
        }
        if (! $page['has_more']) {
            return "（offset {$page['offset']}，has_more=false，已到末页）";
        }

        $statusHint = $status !== null ? "、status={$status}" : '、保持当前 status 为空';

        return "（offset {$page['offset']}，has_more=true；下一页保持 limit={$limit}{$statusHint}，传 offset={$page['next_offset']}）";
    }

    private function jsonText(mixed $v): string
    {
        return (string) json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isRuntimeHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{12}$/', $hash) === 1;
    }

    private function isTodoId(string $id): bool
    {
        return preg_match('/^[0-9a-zA-Z]{20,32}$/', $id) === 1;
    }
}
