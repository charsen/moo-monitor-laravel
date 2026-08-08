# CLAUDE.md

本仓库的完整协作、采集边界、host 安全、Cloud 契约和兼容性规则统一维护在 [`AGENTS.md`](./AGENTS.md)。开始任务前必须完整阅读并遵守它，本文件不维护第二份重复规则。

特别提醒：

- 开工先完整阅读 `notes.md`；关键 Laravel 行为要到支持版本源码中核实。
- 监控必须 fail-safe，不得改变 host 响应、阻塞请求、漏掉未确认缓冲或形成自采集回环。
- 本地存储、环境 scope、逐条 ack、Cloud ability 和 MCP JSON-RPC 都是跨仓持久契约。
- 主测试环境通过不代表声明的 Laravel/PHP 全兼容面已验证。
