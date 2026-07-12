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

    public function __construct(private CloudToolset $toolset) {}

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

            $msg = json_decode($line, true);
            if (! is_array($msg)) {
                $this->send(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]);

                continue;
            }

            $this->dispatch($msg);
        }
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
                    $this->reply($id, ['tools' => $this->toolset->definitions()]);
                    break;

                case 'tools/call':
                    $this->reply($id, $this->toolset->call(
                        (string) ($params['name'] ?? ''),
                        (array) ($params['arguments'] ?? []),
                    ));
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
        fwrite(STDOUT, json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fflush(STDOUT);
    }
}
