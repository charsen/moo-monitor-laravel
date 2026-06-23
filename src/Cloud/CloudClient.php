<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Cloud;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * moo-scaffold-cloud intake 的纯传输客户端。
 *
 * 纯传输：只负责「把一批记录 POST 到某个 intake 端点」，
 * 不读 config、不判 enabled、不管增量游标——那些由 CloudSync 编排。
 *
 * 契约（与云端 RuntimeIntakeController / SlowQueryIntakeController 对齐）：
 *   POST {base_url}/api/v1/{runtimes|slow-queries}/intake
 *   body = { token: <项目 token>, records: [ <yaml 记录原样>, ... ] }
 *   认证 = body 里的 token(ResolveProjectToken 中间件)；云端按 (project, hash) upsert。
 *   响应 = { ok: bool, saved: int } 或 { ok:false, error: string }
 */
class CloudClient
{
    public const PATH_RUNTIMES = 'api/v1/runtimes/intake';

    public const PATH_SLOW_QUERIES = 'api/v1/slow-queries/intake';

    public const PATH_SUMMARY = 'api/v1/summary';

    public const PATH_HEARTBEAT = 'api/v1/heartbeat';

    public const PATH_RUNTIMES_LIST = 'api/v1/runtimes/list';

    public const PATH_RUNTIMES_GET = 'api/v1/runtimes/get';

    public const PATH_RUNTIMES_RESOLVE = 'api/v1/runtimes/resolve';

    public const PATH_TODOS_LIST = 'api/v1/todos/list';

    public const PATH_TODOS_GET = 'api/v1/todos/get';

    public const PATH_TODOS_STATUS = 'api/v1/todos/status';

    private string $baseUrl;

    private string $token;

    private int $timeout;

    private bool $verify;

    public function __construct(?array $cfg = null)
    {
        $cfg = $cfg ?? (array) config('moo-monitor.cloud', []);

        $this->baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $this->token   = (string) ($cfg['token'] ?? '');
        $this->timeout = (int) ($cfg['timeout'] ?? 5);
        $this->verify  = (bool) ($cfg['verify'] ?? true);
    }

    /** base_url + token 是否都已配置（缺一不可发请求）。 */
    public function configured(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * 推送一批记录到指定 intake 路径。失败不抛异常，统一返回结构，由调用方决定是否前进游标。
     *
     * @param array<int,array<string,mixed>> $records
     *
     * @return array{ok:bool,status:int,saved:int,error:?string}
     */
    public function send(string $path, array $records): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'saved' => 0, 'error' => 'cloud base_url / token 未配置'];
        }
        if ($records === []) {
            return ['ok' => true, 'status' => 0, 'saved' => 0, 'error' => null];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            // 瞬时失败（连接重置 / DNS 抖动 / 超时）最多尝试 3 次（= 重试 2 次）、200ms 退避，
            // 避免一次网络抖动就让整批失败、干等下一分钟调度才重试。
            // 5xx/4xx 响应不在此重试（走幂等的下一轮 push，坏 token 类快速失败）。
            // 注意 Laravel Http::retry(N) 的 N 是「总尝试次数」，不是「重试次数」。
            $resp = Http::retry(3, 200, throw: false)
                ->timeout($this->timeout)
                ->withOptions(['verify' => $this->verify])
                ->acceptJson()
                ->asJson()
                ->post($url, ['token' => $this->token, 'records' => array_values($records)]);

            $body = (array) ($resp->json() ?? []);
            // 契约：saved 必须等于 records.length，否则整批视为失败、游标不前进。saved 缺席时用 -1 哨兵
            // (records 非空，见上方提前返回 → -1 永不等于 count)→ ok=false → 不前进游标 → 下轮幂等重推。
            // 不能乐观默认成 count(records)：那会在「无法确认云端到底存了几条」时仍前进游标并回收本地 = 丢数据。
            $saved = array_key_exists('saved', $body) ? (int) $body['saved'] : -1;
            $ok    = $resp->successful() && ($body['ok'] ?? false) === true && $saved === count($records);

            return [
                'ok'     => $ok,
                'status' => $resp->status(),
                'saved'  => $saved,
                'error'  => $ok ? null : (string) ($body['error'] ?? ($resp->successful() ? "saved {$saved}/" . count($records) : ('HTTP ' . $resp->status()))),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'saved' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * 只读拉取本项目云端汇总（供 scaffold 首页「云端汇聚」面板）。
     *
     * 用同一个提报 token(云端读接口挂 project.token:runtimes，提报 token 必带该能力，
     * 故零额外凭据)。这是交互态（随首页渲染），所以：超时收到 ≤4s、只重试 1 次 ——
     * 宁可这次拿不到也别卡首页；调用方（ScaffoldController）再叠一层 cache。失败不抛。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function fetchSummary(int $limit = 5): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cloud base_url / token 未配置'];
        }

        $url = $this->baseUrl . '/' . ltrim(self::PATH_SUMMARY, '/');

        try {
            $resp = Http::retry(2, 100, throw: false)
                ->timeout(max(1, min($this->timeout, 4)))
                ->withOptions(['verify' => $this->verify])
                ->acceptJson()
                ->asJson()
                ->post($url, ['token' => $this->token, 'limit' => $limit]);

            $body = (array) ($resp->json() ?? []);
            $ok   = $resp->successful() && ($body['ok'] ?? false) === true;

            return [
                'ok'     => $ok,
                'status' => $resp->status(),
                'data'   => $ok ? $body : null,
                'error'  => $ok ? null : (string) ($body['error'] ?? ('HTTP ' . $resp->status())),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * 心跳：moo:cloud:push 每次跑（含「无变化」的空跑）都打一拍，云端据此把
     * projects.last_heartbeat_at 刷新成「最近一次推送管道还活着」的时刻 —— 云端的
     * 「推送中断」哨兵只认这个真心跳，不再拿异常数据（出问题才有）当心跳误报。
     *
     * best-effort：短超时、重试 1 次、失败不抛、不前进任何游标 —— 心跳本身绝不能
     * 拖慢或中断真正的记录推送。用同一个提报 token(云端 /api/v1/heartbeat 挂
     * project.token:runtimes，提报 token 必带该能力，故零额外凭据)。
     */
    public function heartbeat(): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $url = $this->baseUrl . '/' . ltrim(self::PATH_HEARTBEAT, '/');

        try {
            $resp = Http::retry(2, 100, throw: false)
                ->timeout(max(1, min($this->timeout, 4)))
                ->withOptions(['verify' => $this->verify])
                ->acceptJson()
                ->asJson()
                ->post($url, ['token' => $this->token]);

            $body = (array) ($resp->json() ?? []);

            return $resp->successful() && ($body['ok'] ?? false) === true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 列出本项目云端「待处理」runtime 错误（供 moo:cloud:mcp 的 list_open_runtimes 工具）。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function fetchRuntimes(int $limit = 20, ?string $status = null): array
    {
        $body = ['limit' => $limit];
        if ($status !== null) {
            $body['status'] = $status;
        }

        return $this->read(self::PATH_RUNTIMES_LIST, $body);
    }

    /**
     * 取单条 runtime 的完整上下文 + AI markdown(get_runtime 工具)。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function fetchRuntime(string $hash, bool $withPayload = false): array
    {
        return $this->read(self::PATH_RUNTIMES_GET, ['hash' => $hash, 'with_payload' => $withPayload]);
    }

    /**
     * 修复后回写「已解决」（resolve_runtime 工具）。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function resolveRuntime(string $hash, ?string $note = null, ?string $by = null): array
    {
        return $this->read(self::PATH_RUNTIMES_RESOLVE, array_filter([
            'hash'        => $hash,
            'note'        => $note,
            'resolved_by' => $by,
        ], fn ($v) => $v !== null));
    }

    /**
     * 列出本项目云端「可执行」待办（供 moo:cloud:mcp 的 list_open_todos 工具）。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function fetchTodos(int $limit = 20, ?string $status = null): array
    {
        $body = ['limit' => $limit];
        if ($status !== null) {
            $body['status'] = $status;
        }

        return $this->read(self::PATH_TODOS_LIST, $body);
    }

    /**
     * 取单条待办的完整上下文 + AI markdown(get_todo 工具)。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function fetchTodo(string $id): array
    {
        return $this->read(self::PATH_TODOS_GET, ['id' => $id]);
    }

    /**
     * 认领（in_progress）/ 完成（done）待办，闭环回写（update_todo_status 工具）。
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    public function updateTodoStatus(string $id, string $status, ?string $note = null, ?string $by = null): array
    {
        return $this->read(self::PATH_TODOS_STATUS, array_filter([
            'id'     => $id,
            'status' => $status,
            'note'   => $note,
            'by'     => $by,
        ], fn ($v) => $v !== null));
    }

    /**
     * 交互态只读/小写请求的共用发送逻辑（token 注入 + 同款超时/重试/错误归一）。
     * 与 fetchSummary 同策略：≤4s、重试 1 次、失败不抛。
     *
     * @param array<string,mixed> $body
     *
     * @return array{ok:bool,status:int,data:?array<string,mixed>,error:?string}
     */
    private function read(string $path, array $body): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cloud base_url / token 未配置'];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        try {
            // 钳下限 1s:timeout 误配成 0 时 min(0,4)=0,Guzzle 会当「无限等待」——
            // 在常驻的 moo:cloud:mcp（阻塞串行读）里足以卡死整个 server。
            $resp = Http::retry(2, 100, throw: false)
                ->timeout(max(1, min($this->timeout, 4)))
                ->withOptions(['verify' => $this->verify])
                ->acceptJson()
                ->asJson()
                ->post($url, ['token' => $this->token] + $body);

            $payload = (array) ($resp->json() ?? []);
            $ok      = $resp->successful() && ($payload['ok'] ?? false) === true;

            return [
                'ok'     => $ok,
                'status' => $resp->status(),
                'data'   => $ok ? $payload : null,
                'error'  => $ok ? null : (string) ($payload['error'] ?? ('HTTP ' . $resp->status())),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $e->getMessage()];
        }
    }
}
