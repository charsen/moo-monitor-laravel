# Changelog

`moo-monitor-laravel` 版本变更记录,按 [Keep a Changelog](https://keepachangelog.com/) + [SemVer](https://semver.org/) 风格。

## [0.1.0] — 2026-06-12

首个版本:从 [moo-scaffold](https://gitee.com/charsen/moo-scaffold) 3.8.x 抽离监控链路成独立包(对标 moo-monitor-vue 之于 Vue 3)。YAML 字段结构、hash 算法、云端 intake 契约与 scaffold 时代完全一致 —— 云端零改动,存量记录无缝延续。

### Added

- **采集层**(自 scaffold 平移,命名空间 `Mooeen\Monitor\Recorder`):`RuntimeErrorRecorder`(reportable 异常落盘,trace + 源码片段 + 脱敏)、`SqlSlowRecorder` + `SqlSlowListener`(QueryExecuted 超阈值落盘)、四个共享 trait(三桶管理 / 原子写 / 日上限 / 脱敏)。
- **云端层**(`Mooeen\Monitor\Cloud`):`CloudClient`(intake / summary / heartbeat / MCP 读修的纯传输)、`CloudSync`(增量游标 + 分批推送 + 本地回收)。
- **命令**:`moo:cloud:push`、`moo:cloud:mcp`(命令名与 scaffold 时代一致,crontab / MCP 配置无感);新增 `moo:monitor:migrate`(≤3.8 旧布局迁移,幂等,替代已退役的 `moo:cloud:adopt`)。
- **MonitorProvider**:事件监听、singleton、调度自动挂载;**新增 reportable 自动挂钩**(`exception.auto_hook`,默认开)—— 宿主零接入,`ExceptionDispatcher` 用 WeakMap 防双计,与旧的手动接入并存也不重复计数。
- **测试**:81 个 Pest 用例(Testbench,零 env 全绿),含从 scaffold 平移的全部监控测试 + migrate / 防双计 / .gitignore 新用例。

### Changed(相对 scaffold ≤3.8 的行为差异)

- 配置独立:`config/moo-monitor.php` + `MOO_MONITOR_*` env(旧 `SCAFFOLD_*` 不再生效、无兼容回落;`moo:cloud:push` 检测到旧名残留会提示)。
- 本地缓冲移位:`base_path('scaffold/{runtimes,sql-slows}')` → `storage_path('moo-monitor/{runtimes,sql-slows}')`,目录自动写自我屏蔽 `.gitignore`;推送游标移至 `storage/moo-monitor/cloud-sync.json`。
- headless:不注册任何路由 / 视图(scaffold 的 `/scaffold/cloud` 控制台等 UI 留在 scaffold,调本包的类)。
