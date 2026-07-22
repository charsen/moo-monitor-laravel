<?php declare(strict_types=1);

namespace Mooeen\Monitor\Command;

use Throwable;

/**
 * moo:cloud:mcp 的传输层：手写极简 stdio MCP server 的 stdin 循环 + newline-delimited JSON-RPC 2.0 编解码。
 * 从 CloudMcpCommand 拆出（P5）—— 只实现 initialize / tools/list / tools/call / ping 四个方法的协议路由，
 * 具体「有哪些工具、怎么执行」下放 CloudToolset。零额外依赖。
 *
 * 协议消息按行从 STDIN 读、往 STDOUT 写；诊断信息一律走 STDERR —— STDOUT 必须保持纯 JSON-RPC，
 * 否则会污染协议、客户端解析失败。
 */
class McpLoop
{
    /**
     * 服务端支持的协议版本（新→旧）。客户端请求命中则回显，否则回退到首个（即最新）——
     * 符合 MCP 版本协商：不支持对方所请版本时回最新支持版，由客户端决定是否继续。
     * 含 2025-* 版本，现代客户端（Claude Code / Codex）即可协商到支持 tool annotations 的协议。
     */
    private const SUPPORTED_PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct(private CloudToolset $toolset, $stdout = null, $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    /**
     * 阻塞式读取，每行一条 JSON-RPC 消息；客户端关闭 stdin(EOF)即退出。
     *
     * @param resource $stdin
     */
    public function run($stdin): void
    {
        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

                continue;
            }
            // MCP 每行是一条 JSON-RPC Request/Notification 对象；顶层数组（batch）或标量
            // 不在本极简 server 的传输契约内，属于 Invalid Request 而不是 notification。
            if (! is_object($decoded)) {
                $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']]);

                continue;
            }

            $msg = json_decode($line, true);

            $this->dispatch($msg);
        }
    }

    /** 路由单条 JSON-RPC 消息。 */
    private function dispatch(array $msg): void
    {
        $hasId = array_key_exists('id', $msg);
        $id    = $hasId ? $msg['id'] : null;
        // JSON-RPC id 只能是 String / Number / Null。畸形 id 不得原样回显，错误响应统一 id=null。
        if ($hasId && ! is_string($id) && ! is_int($id) && ! is_float($id) && $id !== null) {
            $this->replyError(null, -32600, 'Invalid Request');

            return;
        }
        if (($msg['jsonrpc'] ?? null) !== '2.0' || ! isset($msg['method']) || ! is_string($msg['method']) || $msg['method'] === '') {
            $this->replyError($id, -32600, 'Invalid Request');

            return;
        }
        if (array_key_exists('params', $msg) && ! is_array($msg['params'])) {
            $this->replyError($id, -32602, 'Invalid params');

            return;
        }

        // 只有通过 Request 结构校验的无 id 消息才是 notification；合法 notification 一律不回包。
        // 目前没有需要处理副作用的 notification，initialized / cancelled / 未知方法均直接忽略。
        if (! $hasId) {
            return;
        }

        $method = $msg['method'];
        $params = $msg['params'] ?? [];

        try {
            switch ($method) {
                case 'initialize':
                    if (array_key_exists('protocolVersion', $params) && ! is_string($params['protocolVersion'])) {
                        $this->replyError($id, -32602, 'Invalid params');

                        break;
                    }
                    $this->reply($id, $this->onInitialize($params));
                    break;

                case 'tools/list':
                    $this->reply($id, ['tools' => $this->toolset->definitions()]);
                    break;

                case 'tools/call':
                    if (! isset($params['name']) || ! is_string($params['name']) || $params['name'] === ''
                                                 || (array_key_exists('arguments', $params) && ! is_array($params['arguments']))) {
                        $this->replyError($id, -32602, 'Invalid params');

                        break;
                    }
                    $this->reply($id, $this->toolset->call(
                        $params['name'],
                        $params['arguments'] ?? [],
                    ));
                    break;

                case 'ping':
                    $this->reply($id, (object) []);
                    break;

                default:
                    $this->replyError($id, -32601, "Method not found: {$method}");
            }
        } catch (Throwable $e) {
            fwrite($this->stderr, '[moo:cloud:mcp] 处理 ' . $method . ' 异常：' . $e->getMessage() . "\n");
            $this->replyError($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    /** initialize：回应协议版本 + 能力 + 服务端信息。只回显支持的版本，否则回退。 */
    private function onInitialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? '';
        $version   = in_array($requested, self::SUPPORTED_PROTOCOLS, true)
            ? $requested
            : self::SUPPORTED_PROTOCOLS[0];

        return [
            'protocolVersion' => $version,
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => ['name' => 'moo-cloud', 'version' => '1.0.0'],
            'instructions'    => $this->toolset->instructions(),
        ];
    }

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
        fwrite($this->stdout, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush($this->stdout);
    }
}
