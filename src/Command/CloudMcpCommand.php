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
 * 命令类只负责装配（P5 拆分）：传输层 stdin 循环 + JSON-RPC 编解码在 McpLoop，
 * 工具定义 + handler 在 CloudToolset。
 *
 * 接入（在任意装了 moo-monitor-laravel 的项目仓库根目录）：
 *   claude mcp add moo-cloud -- php artisan moo:cloud:mcp
 * 之后 AI 即有六个工具：runtime 三件套（list_open_runtimes / get_runtime / resolve_runtime）
 * + 待办三件套（list_open_todos / get_todo / update_todo_status）。
 * 凭据复用 moo-monitor.cloud 已配置的 base_url + token（私密 Host token 必带独立 mcp 能力）。
 */

namespace Mooeen\Monitor\Command;

use Illuminate\Console\Command;
use Mooeen\Monitor\Cloud\CloudClient;

class CloudMcpCommand extends Command
{
    protected $name = 'moo:cloud:mcp';

    protected $description = '以 MCP server 形式把云端 runtime 错误与待办暴露给本仓库的 AI（拉取 / 查看 / 处理回写）';

    protected $signature = 'moo:cloud:mcp';

    public function handle(): int
    {
        @set_time_limit(0);
        $cloud = new CloudClient;

        if (! $cloud->configured()) {
            // 不退出：仍完成握手，让客户端连上；具体工具调用再返回明确错误。
            fwrite(STDERR, "[moo:cloud:mcp] 警告：moo-monitor.cloud base_url / token 未配置，工具调用将失败。\n");
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            fwrite(STDERR, "[moo:cloud:mcp] 无法打开 STDIN。\n");

            return self::FAILURE;
        }

        (new McpLoop(new CloudToolset($cloud)))->run($stdin);

        return self::SUCCESS;
    }
}
