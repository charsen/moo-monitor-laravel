# Changelog

`moo-monitor-laravel` 版本变更记录，按 [Keep a Changelog](https://keepachangelog.com/) + [SemVer](https://semver.org/) 风格。

## [0.1.5] — 2026-06-23

适配云端 todos 新增的 `category`（bug / task）分类透出；并把文档与代码注释里误用的中文半角标点统一为全角。

### Added

- **MCP 待办分类透出**：云端 2026-06-22 给 todos 加了 `category` 字段（enum `bug`|`task`，缺省 `bug`；`bug` = Chrome 扩展采集的缺陷、`task` = 云端管理界面手动新建的任务）。`get_todo` 的「元信息」块新增「类型」一项（`bug` → `Bug（缺陷）`、`task` → `任务`、缺失/未知值原样透传不臆断为 Bug），`list_open_todos` 工具描述与 MCP 工作流说明同步注明该字段含义。`category` 是纯响应字段、无需改 `CloudClient`；云端 list 端点只按 `status` 过滤，故未加 `category` 过滤参数。测试 117 → 121（新增 `get_todo` 分类渲染 4 例 dataset，覆盖 `match` 四个分支）。

### Changed

- **文档与注释标点全角化**：README / CHANGELOG / 配置注释 / `src` 全量 PHP 注释与命令输出文案中误用的半角 `, : ; ( ) ?` 统一改为全角 `，：；（）？`，中文引述统一用「」。规则是只在中文字符紧邻处转换，命令串（`moo:cloud:push` 等）、函数调用括号、路径、SQL 占位符 `?`、代码示例一律保持原样（`php -l` + 全量测试守护，零行为改动）。`docs/cloud-api-contract.md` 补充 todos `category` 字段与「list 无 category 过滤」说明。

## [0.1.4] — 2026-06-18

放宽 composer 约束以支持 Laravel 10 / 11 宿主（此前仅 `^12`），便于旧版宿主经 VCS 接入。

### Changed

- **框架约束放宽**：`php` `^8.2` → `^8.1`、`laravel/framework` `^12.0` → `^10.10 || ^11.0 || ^12.0`、`symfony/yaml` `^7.0` → `^6.4 || ^7.0`。运行时代码本就只用 Laravel 8+ 通用 API(`reportable` / `callAfterResolving` / `QueryExecuted` / `Http::retry` / `Yaml` / `WeakMap`)，无 L11/12 或 PHP 8.2 专属语法，对 L10 宿主零行为差异。`require-dev`(testbench `^10` = L12)维持不变，维护侧仍以 L12 为主测目标。

## [0.1.3] — 2026-06-14

阶段性业务代码审查与加固（多维度评审 + 对抗复核，确认 16 项）+ 新增接入自检命令；测试 100 → 117。

### Added

- **`moo:cloud:test` 接入自检命令**：不依赖真实异常/慢查询发生，直接推一条可识别的自检 runtime + 一条自检慢 SQL 走真实 intake 端点，逐步反馈「配置检查 → 心跳 → 推送 runtime → 推送慢 SQL」，让新手一键确认「采集 → 推送 → 云端」整条管道是否有效。自检记录可识别（`SelfTestException` / SQL 带 `self-test` 标记）、幂等不堆积（固定 hash 只 upsert），默认保留为「未处理」便于在云端亲眼确认数据已到达（`--resolve` 推送后自动解决）；支持 `--type=runtimes|slow_sql|both`。复用云端既有端点，契约零改动。

### Fixed

- **失败隔离（核心不变量）**：异常分发 / 慢 SQL 监听 / 落盘的兜底日志写入统一走绝不抛错的 `SafelyLogs::safeLog`，并把 `ExceptionDispatcher::dispatch()` 整个主体纳入兜底 —— 修复「日志后端（database/slack 等）在异常上报时不可用 → 写日志自身抛错 → 冒泡进宿主异常链（白屏）/ 查询执行（成功查询变抛错）」。
- **密钥泄露**：写盘失败的诊断日志改用 `maskUrl` 脱敏请求 URL，不再把 `?token=` / `?api_key=` 明文写进宿主 `laravel.log`。
- **数据损坏**：payload 字符串截断改为字符级 `mb_substr` + 非负长度，中文/emoji 不再被字节切断成非法 UTF-8 而被 yaml 当二进制 base64 编码；`string_truncate < 20` 不再反向放大输出。
- **聚合不变量**：`deleted` 桶里的同 hash 复发时从其实际所在桶搬回 `open` 复活，消除「同 hash 同时存在两桶」的跨桶重复。
- **契约健壮性**：云端响应缺失 `saved` 字段时按整批失败处理（fail-closed），不再乐观前进游标导致丢数据。
- **采集准确性**：`max_open` 写盘闸在缓存判「满」时实测复核一次，避免 prune / migrate 等外部减量后缓存陈旧误判桶满、静默丢弃新 hash。
- **脱敏一致性**：`trace.full` 补 `maskSensitiveSql`，与 `exception.message` 的脱敏强度对齐。

### Changed

- 推送失败时输出卡住的记录 hash（`moo:cloud:push` 打印 + 后台调度写日志）：单条被云端持久拒收的记录会卡住整类游标并使本地缓冲累积，现在可被发现与定位。
- `open` 数缓存仅在「新建文件」时失效，刷新已有记录的热点路径不再无谓清缓存。
- README 补 `MOO_MONITOR_CLOUD_TIMEOUT` / `VERIFY` / `SCHEDULE` 配置项与「推送一直失败」排障章节；`ManagesBucketedRecords` 注明并发下 `count` 为 best-effort 近似值。

## [0.1.2] — 2026-06-13

### Added

- 重写 README，补充业务功能、安装接入、云端推送、MCP 和迁移说明。
- 新增 `SECURITY.md` 与云端 API 契约文档。

### Fixed

- 本地 YAML 桶只信任合法 12 位 hash 文件名，跳过畸形文件名，避免影响列表、计数和推送。
- 云端推送以 YAML 时间戳做增量判断，避免旧 mtime 文件漏推；本地 resolved 回收改用记录时间判断。
- 云端 intake 返回部分保存数量时视为失败，避免游标误前进。
- MCP runtime 工具本地校验 hash，非法输入不再打云端。
- 加强异常消息、payload、慢 SQL、INSERT values、Authorization Basic、Bearer/JWT 等敏感信息脱敏。
- 修复慢 SQL binding 值含 `?`、`DateTimeInterface` binding 等场景的 SQL 记录问题。
- 移除命令运行期直接读 Laravel `env()` 的用法，兼容配置缓存。

### Changed

- 新增 Composer 维护脚本：`check:composer`、`lint`、`format`、`quality`。
- 测试扩展到 100 个用例，覆盖云端同步、MCP、存储边界和脱敏回归。

### Removed

- 移除 Larastan / PHPStan 与 GitHub Actions CI（及 `analyse` 脚本）：质量门禁完全依赖 Pest 用例覆盖。

## [0.1.1] — 2026-06-12

### Fixed

- 数据目录 `.gitignore` 改为纯 `*`，避免监控缓冲目录污染宿主项目 git 状态。
- 迁移命令清理 git-sync 时代的 `.gitkeep` / `.gitignore` 残留，空壳旧目录支持重跑补清。
- 迁移游标合并改取较旧水位，避免旧布局未推记录被静默漏推。
- 慢 SQL 默认过滤恢复 `system_operation_logs` 和 `migrations` 两类噪音。

## [0.1.0] — 2026-06-12

首个版本：从 [moo-scaffold](https://gitee.com/charsen/moo-scaffold) 3.8.x 抽离监控链路成独立包（对标 moo-monitor-vue 之于 Vue 3）。YAML 字段结构、hash 算法、云端 intake 契约与 scaffold 时代完全一致 —— 云端零改动，存量记录无缝延续。

### Added

- **采集层**（自 scaffold 平移，命名空间 `Mooeen\Monitor\Recorder`）：`RuntimeErrorRecorder`（reportable 异常落盘，trace + 源码片段 + 脱敏）、`SqlSlowRecorder` + `SqlSlowListener`（QueryExecuted 超阈值落盘）、四个共享 trait（三桶管理 / 原子写 / 日上限 / 脱敏）。
- **云端层**(`Mooeen\Monitor\Cloud`):`CloudClient`（intake / summary / heartbeat / MCP 读修的纯传输）、`CloudSync`（增量游标 + 分批推送 + 本地回收）。
- **命令**：`moo:cloud:push`、`moo:cloud:mcp`（命令名与 scaffold 时代一致，crontab / MCP 配置无感）；新增 `moo:monitor:migrate`（≤3.8 旧布局迁移，幂等，替代已退役的 `moo:cloud:adopt`）。
- **MonitorProvider**：事件监听、singleton、调度自动挂载；**新增 reportable 自动挂钩**（`exception.auto_hook`，默认开）—— 宿主零接入，`ExceptionDispatcher` 用 WeakMap 防双计，与旧的手动接入并存也不重复计数。
- **测试**：81 个 Pest 用例（Testbench，零 env 全绿），含从 scaffold 平移的全部监控测试 + migrate / 防双计 / .gitignore 新用例。

### Changed（相对 scaffold ≤3.8 的行为差异）

- 配置独立：`config/moo-monitor.php` + `MOO_MONITOR_*` env（旧 `SCAFFOLD_*` 不再生效、无兼容回落；`moo:cloud:push` 检测到旧名残留会提示）。
- 本地缓冲移位：`base_path('scaffold/{runtimes,sql-slows}')` → `storage_path('moo-monitor/{runtimes,sql-slows}')`，目录自动写自我屏蔽 `.gitignore`；推送游标移至 `storage/moo-monitor/cloud-sync.json`。
- headless：不注册任何路由 / 视图（scaffold 的 `/scaffold/cloud` 控制台等 UI 留在 scaffold，调本包的类）。
