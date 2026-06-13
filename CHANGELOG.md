# Changelog

`moo-monitor-laravel` 版本变更记录,按 [Keep a Changelog](https://keepachangelog.com/) + [SemVer](https://semver.org/) 风格。

## [0.1.2] — 2026-06-13

### Added

- 重写 README,补充业务功能、安装接入、云端推送、MCP 和迁移说明。
- 新增 GitHub Actions CI,覆盖 Composer 校验、Pint、Larastan/PHPStan 和 Pest。
- 新增 `SECURITY.md` 与云端 API 契约文档。

### Fixed

- 本地 YAML 桶只信任合法 12 位 hash 文件名,跳过畸形文件名,避免影响列表、计数和推送。
- 云端推送以 YAML 时间戳做增量判断,避免旧 mtime 文件漏推;本地 resolved 回收改用记录时间判断。
- 云端 intake 返回部分保存数量时视为失败,避免游标误前进。
- MCP runtime 工具本地校验 hash,非法输入不再打云端。
- 加强异常消息、payload、慢 SQL、INSERT values、Authorization Basic、Bearer/JWT 等敏感信息脱敏。
- 修复慢 SQL binding 值含 `?`、`DateTimeInterface` binding 等场景的 SQL 记录问题。
- 移除命令运行期直接读 Laravel `env()` 的用法,兼容配置缓存。

### Changed

- 新增 Composer 维护脚本:`check:composer`、`lint`、`format`、`analyse`、`quality`。
- 测试扩展到 100 个用例,覆盖云端同步、MCP、存储边界和脱敏回归。

## [0.1.1] — 2026-06-12

### Fixed

- 数据目录 `.gitignore` 改为纯 `*`,避免监控缓冲目录污染宿主项目 git 状态。
- 迁移命令清理 git-sync 时代的 `.gitkeep` / `.gitignore` 残留,空壳旧目录支持重跑补清。
- 迁移游标合并改取较旧水位,避免旧布局未推记录被静默漏推。
- 慢 SQL 默认过滤恢复 `system_operation_logs` 和 `migrations` 两类噪音。

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
