# Changelog

`moo-monitor-laravel` 版本变更记录，按 [Keep a Changelog](https://keepachangelog.com/) + [SemVer](https://semver.org/) 风格。

## [0.1.13] — 2026-07-22

修复同一 Laravel Host 通过多份 `.env.XXX` 连接不同 Cloud 项目时，本地缓冲、同步水位与自动调度可能跨项目串用的问题。

### Added

- **统一 Storage Scope**：新增 `MOO_MONITOR_STORAGE_SCOPE`（默认 `auto`），在真实使用 `artisan --env=XXX` 且所选环境不同于 `APP_ENV` 时，为 runtime、slow SQL、cursor、partial ack、同步锁、回收范围和 recorder cache key 使用同一规范化 scope。
- **多环境迁移与文档**：旧版数据迁移目标遵循当前 scope；README 补齐单环境兼容、显式 scope、关闭 auto scope 和多 scheduler 进程用法。

### Fixed

- **多项目本地数据隔离**：相同 hash 不再跨项目聚合；项目 A 的 cursor / partial ack 不再抑制或误确认项目 B 的记录，`--all` 与本地回收也不会越过当前 scope。
- **自动调度继承环境与可靠解锁**：父级 `schedule:run/work --env=XXX` 注册的 `moo:cloud:push` 子命令会原样携带 `--env`；参数由 Laravel Schedule 转义，不同环境的 `withoutOverlapping` mutex 自然独立。scoped push 留在原 scheduler 进程前台执行，避免后台 `schedule:finish` 因不继承 `--env` 而找不到环境专属锁；未指定 `--env` 的单环境部署继续后台执行并保持历史路径。

### Verified

- `composer quality`：213 passed / 767 assertions。
- `composer smoke:lower`：Laravel Framework 8.83.29 安装、provider boot、命令注册、scoped scheduler、调度失败采集与真实 HTTP 重试路径通过。
- 真实多环境宿主完成六份 `.env.XXX` 的 Runtime / Slow SQL 推送、55 条分页与分批压力、逐条回执、Todo 状态流转、MCP 权限和跨项目隔离回归；重复推送均收敛为 0 条变化。

## [0.1.12] — 2026-07-22

加固 Cloud 增量确认、MCP 读写契约与跨 Laravel 版本的调度失败采集，解决单条失败拖累整批、历史记录重复上报和监控命令自反馈问题。

### Fixed

- **逐条 Cloud 回执**：严格消费 `saved / filtered / skipped / results` 闭合契约；已保存或过滤的记录立即确认，临时失败只重试自身，不可重试记录移入对应类型的 `cloud-rejected/`，已确认的 resolved 文件可单独回收。
- **确认水位与并发安全**：清理本地文件已不存在时的陈旧 ack；同类型同步以非阻塞文件锁覆盖扫描、HTTP、逐条确认和状态写入，避免手动、调度或页面入口并发覆盖水位。
- **保留 open 累计锚点**：`local_retention_days` 不再删除 stale open YAML，避免同 hash 从低 count 重建后令云端累计数和复发信号冻结；仅回收已确认的 resolved 快照。
- **MCP 分页**：Runtime/Todo list 新增 `offset`，严格校验 Cloud 返回的 `offset / has_more / next_offset`；翻页提示要求保持相同 `status / limit`，旧 Cloud 在后续页缺少分页回执时 fail-closed，避免无限重复第一页。
- **MCP 协议与参数防御**：补齐 JSON-RPC 2.0 请求、notification、id、params 和协议版本校验；列表、hash、todo id、布尔值及状态参数拒绝畸形类型，避免 warning、误过滤或敏感 payload 意外带出。
- **调度失败去重与防回环**：兼容 Laravel 8 的 Finished-only 与 Laravel 12 的 Finished → Failed → report 事件序列，同一任务只采集一次；`moo:cloud:*` 自身非零退出不再写回 runtime 缓冲，真实命令异常仍保留。

### Changed

- MCP Runtime/Todo 读写改用独立 `mcp` ability；Host 的 `runtimes / slow_queries` 只负责上报，Chrome 的 `todos` 只负责待办 intake。
- Todo MCP category 与云端统一为 `bug / frontend_bug / backend_bug / task` 四类。
- Laravel 8 下限冒烟增加调度事件序列与真实不可达 Cloud 重试路径，防止只在新框架测试通过。

### Verified

- `composer quality`：203 passed / 717 assertions。
- `composer smoke:lower`：Laravel Framework 8.83.29 安装、provider boot、命令注册、调度失败采集及真实 HTTP 重试路径通过。
- 与 moo-scaffold、moo-scaffold-cloud 的 partial ack、分页和权限契约组合回归通过。

## [0.1.11] — 2026-07-11

发版前低版本兼容冒烟 + 0.1.10 遗留文档补齐。无运行时代码改动，`src/` 与 0.1.10 逐字节一致。

### Added

- **发版前低版本冒烟脚本**：新增 `composer smoke:lower`。Pest 套件只在 Laravel 12 上跑，版本相关的 API 兼容问题可能「测试全绿却在老宿主 fatal」；该脚本临时建一个目标版本的 Laravel app、用 path 仓库装本包，断言服务提供者能 boot、命令注册、`moo:cloud:push --dry-run` 跑通。默认最低支持版本 Laravel 8，可 `composer smoke:lower -- '^9.0'` 指定 9 / 10 / 11。

### Docs

- **环境要求订正**：README 更正为实际支持 PHP 8.0+ / Laravel 8.54~12（此前漏更）。
- **补齐 0.1.10 配置文档**：README「常用配置」补上 0.1.10 新增的三个采集钩子开关（`MOO_MONITOR_EXCEPTION_LOG_MESSAGE_HOOK` / `_HTTP_5XX_HOOK` / `_SCHEDULE_EXIT_HOOK`），并列出 `runtime.auth_guards` / `runtime.app_frame_prefixes` / `exception.log_context_levels` 三个按宿主布局微调的数组项。
- **补齐采集路径说明**：README「采集范围」补上「调度任务非零退出码」这条 0.1.10 新增路径（原文只写了「调度任务抛出的异常」）。

### Style

- 修复 `CloudMcpCommandTest` 的 Pint 风格告警（随 0.1.5 带入，纯格式）。

## [0.1.10] — 2026-07-09

一次以「捕获精准度」为主线的大版本：先审计出真实 Laravel 宿主里能绕过监控的采集盲区（每条都在框架源码里核对过），补齐五条漏报路径与若干失真点，再把采集存储层从互相引用的 trait 收口为单一抽象基类。云端 intake 契约向后兼容（仅新增可选字段与来源），无破坏性改动。测试 129 → 176（593 断言）。

### Added

- **字符串化异常进日志**：新增 `MOO_MONITOR_EXCEPTION_LOG_MESSAGE_HOOK`（默认开启）。`Log::error($e)` / `Log::error('失败：' . $e->getMessage())` 这类写法在 `MessageLogged` 事件之前已被 `Logger::formatMessage` 把 `Throwable` 强转成字符串，`context` 里没有异常对象，原 `log_context` 钩子全漏。新钩子按日志调用点合成一条记录进同一采集管道（`meta.source = log_message`），并带**防回环双闸**：本包 `safeLog` 输出的日志打 `moo_monitor_internal` 标记、见标记即跳过，叠加 `static` 重入闸，杜绝「写盘失败 → error 日志 → 又被采集」死循环。
- **HttpException 5xx 捕获**：新增 `MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK`（默认开启）。`abort(500/502/503)` 与第三方包抛出的 5xx 都在框架 `internalDontReport` 名单里、`reportable` 主链看不见。改挂 `renderable` 观察者补采（`meta.source = http_5xx`）：只读、返回 `null` 放行框架默认渲染，宿主对外响应分毫不变。
- **调度任务非零退出码捕获**：新增 `MOO_MONITOR_EXCEPTION_SCHEDULE_EXIT_HOOK`（默认开启）。exec 型调度任务不抛异常、只以退出码表示失败，过去完全不可见。监听 `ScheduledTaskFinished` / `ScheduledBackgroundTaskFinished`（后台任务经 `schedule:finish` 回填退出码后发后者，两个都接），退出码为非零整数时合成一条记录（`meta.source = schedule_exit`，附 `command` / `exit_code` / `runtime`）。
- **异常链根因采集**：`exception.previous` 新增，采集包装异常的 `getPrevious()` 链（最多 3 层，逐层双层脱敏），`QueryException` 里的 `PDOException` 等根因不再丢失；聚合 hash 仍按最外层计算，聚合稳定性不变。
- **慢 SQL 连接名**：慢 SQL 记录新增 `at.connection`（来自 `QueryExecuted::$connectionName`），多库宿主可区分慢查询来自哪个连接。
- **危险语境降级采集（lean path）**：fatal / OOM（`FatalError`，或已用内存逼近 `memory_limit` 的 0.9）语境下，采集跳过读源码片段、payload 递归脱敏、trace 正则等重操作，避免在 shutdown 只剩 32KB 保留内存时二次耗尽把这条记录也吞掉；记 `meta.lean = true` 供云端识别。**脱敏不降级**。
- **采集面配置化**：新增 `runtime.auth_guards`（默认 `admin` / `user` / `web`，用 `api` / `sanctum` / 自定义 guard 的宿主可采到登录用户）、`runtime.app_frame_prefixes`（默认 `app/` / `routes/`，`Modules/` / `src/` 等布局的宿主可让调用栈应用帧正确归类）、`exception.log_context_levels`（触发两个日志钩子的级别白名单，默认 `error` 及以上）。
- **热错误计数真实性**：`daily_cap` 冻结期不再直接丢弃计数——改为 `cache` 累加溢出计数、次日首次写盘时回填进 `count`，云端拿到真实累计而非封顶值；`cache` 不可用时退回原「计数偏低」行为（best-effort，不引入热路径文件 IO）。
- **覆盖面契约测试**：新增 `CaptureMatrixTest`，采集覆盖矩阵每条路径一条端到端断言，作为今后所有采集钩子改动的守卫。

### Changed

- **采集存储层收口为抽象基类**：`RuntimeErrorRecorder` / `SqlSlowRecorder` 原靠三个互相引用的 trait（`ManagesBucketedRecords` / `WritesBucketedYaml` / `TracksDailyCap`）拼装，读一个方法要跨 3～4 个文件、且靠 `$this->config` / `$this->basePath` / 常量的隐式契约维系。合并为单一抽象基类 `BucketedYamlRecorder`，把两个记录器逐字重复的 `record()` 骨架（找桶 → 复发搬桶 → 每日上限/溢出 → 满闸 → 原子写盘 → 失败诊断）提为模板方法 `persistAggregated()`；`extractRequest` / `extractContext` / 写失败诊断等收进基类单份。行为不变，既有测试断言原样通过。
- **脱敏能力独立成类**：原 `MasksSensitiveUrl` trait（实际管 URL、SQL 值侧、`INSERT` 列、JWT/Bearer 四类脱敏，名不副实、靠 `$this->config` 隐式取键）抽为 `SensitiveMasker`（构造器注入 `mask_keys`），可脱离 Laravel 纯单测。
- **来源优先级收口单一真源**：`meta.source` 的优先级表统一到 `RuntimeErrorRecorder::SOURCE_PRIORITY`（`ExceptionDispatcher` 复用同一语义），`refresh` 路径的 source 只升不降，不再在 `queue_failed` 与 `log_context` 之间反复升降级、反复刷盘。
- **MCP 命令拆分**：`CloudMcpCommand`（520 行）拆为传输层 `McpLoop`（stdin 循环 + JSON-RPC 编解码）与工具层 `CloudToolset`（工具定义 + 处理器），命令类只剩装配。
- **CloudClient 回归纯传输契约**：心跳 `meta` 组装抽到 `Cloud/HeartbeatMeta::collect()`，`CloudClient::heartbeat()` 改为接收传入 `meta`，不再自己读 config（与类 docblock 声明一致）。心跳请求体形状不变。
- **`MonitorProvider::boot()` 拆为清单式**：`publishesConfig` / `listenSlowQueries` / `hookExceptionReporting` / `scheduleCloudPush` 四个私有方法。

### Fixed

- **console / 队列语境 request 误标**：`request()` 在 artisan / 队列 worker 下从容器解析出的是空 `Request` 对象而非 `null`，导致 console / 队列异常被记成 `GET http://…`（畸形 URL）而非 `CLI`。改为 console 语境显式取 `null`，正确走 CLI 分支。
- **慢 SQL binding 未转义**：`sql_last` 拼接时字符串 binding 未转义单引号 / 反斜杠，值含 `'` 或 `\` 时引号结构损坏，且可能让值侧脱敏正则错位、导致敏感值漏脱敏。改为拼接前转义，与脱敏正则认可的转义形态一致。
- **`tagSource` 绕过冻结**：同一异常来源升级时 `tagSource` 无条件写盘、刷新 `meta.updated_at`，使 `daily_cap` 冻结形同虚设、热错误仍每分钟被推送。补两道闸：来源集合无变化不写盘、当日已达上限不写盘。
- **中文引号统一**：配置注释中误用的弯引号 `“”` 统一为「」，对齐 moo 文案风格。

### Verified

- `composer quality`（composer 校验 + Pint lint + Pest 全量）全绿：176 passed / 593 assertions。
- 经 Orchestra Testbench 的 artisan 入口验证 `moo:cloud:test`：配置守卫（缺 token 明确拦下并打码）、心跳阶段对不可达云端优雅失败（无 fatal / 无异常泄漏）；直接驱动 `buildSelfTestRecord()` 验证重构后的记录构造管道产出形状正确、YAML 序列化与往返解析一致，`request.method` 在 CLI 语境正确为 `CLI`。真实云端往返（推送 → 云端 upsert）需在配置真实 token 的宿主项目执行。

## [0.1.9] — 2026-07-01

### Changed

- 心跳 SDK 版本优先从 Composer 安装信息读取，`MonitorProvider::VERSION` 仅作为 fallback，降低发版忘改常量的风险。
- 同一异常对象被多个入口捕获时，`ExceptionDispatcher` 仍防重复计数，但允许更高价值来源（如 `queue_failed`）升级已落盘记录的 `meta.source`，并保留 `meta.sources` 来源列表。

## [0.1.8] — 2026-07-01

### Added

- **Runtime 来源标记**：`ExceptionDispatcher` / `RuntimeErrorRecorder` 新增来源与元信息参数，运行时记录会写入 `meta.source`。当前来源包括 `reportable`、`log_context`、`queue_failed`、`self_test`，供云端列表、详情与排障诊断区分“异常链 / 日志兜底 / 队列失败 / 接入自检”。
- **队列失败捕获**：新增 `MOO_MONITOR_EXCEPTION_QUEUE_FAILED_HOOK`（默认开启），监听 Laravel `JobFailed` 事件，把队列失败直接落入 runtimes，并附带 connection、queue、job name、attempts 等排查信息。同一异常对象仍复用 `WeakMap` 去重。
- **心跳元信息**：`moo:cloud:push` 心跳 body 新增 SDK 版本、PHP/Laravel 版本、应用名、环境、采集开关、推送开关与 schedule 状态，云端可直接判断“安装了什么版本、开关是否正确、调度是否配置”。

## [0.1.7] — 2026-07-01

### Added

- **日志异常兜底**：新增 `MOO_MONITOR_EXCEPTION_LOG_CONTEXT_HOOK`（默认开启），捕获 `Log::error(..., ['exception' => $e])` 这类只写日志、未进入 `reportable` 的异常。队列 failed 回调 / 业务 catch 里常见的异常现在也会落入本地 runtime 缓冲，并经 `moo:cloud:push` 进入云端 runtimes；同一异常对象若已走过 `reportable`，仍复用 `ExceptionDispatcher` 的 `WeakMap` 去重，不会双计。

### Verified

- Laravel 8.83 / 9.52 / 10.50 / 11.54 / 12.62 临时应用均完成 path 接入、`package:discover`、`artisan list moo`、`moo:cloud:push --dry-run` smoke 验证，服务提供者和命令注册可正常加载。

## [0.1.6] — 2026-06-27

继续放宽框架约束以支持 **Laravel 8** 宿主(`php ^8.0` + `laravel/framework ^8.54`),便于更老的项目经 path / VCS 接入。

### Changed

- **框架约束再放宽**:`php` `^8.1` → `^8.0`、`laravel/framework` 增补 `^8.54 || ^9.0`(现为 `^8.54 || ^9.0 || ^10.10 || ^11.0 || ^12.0`)、`symfony/yaml` 增补 `^5.4`(现为 `^5.4 || ^6.4 || ^7.0`,对齐 L8 自带的 Symfony 5.4)。已核对运行时代码无 PHP 8.1 专属语法(无 enum / readonly / never / first-class callable),且 `WeakMap`、`str_contains` / `str_starts_with`、`callAfterResolving`、`reportable`、`QueryExecuted`、`withoutOverlapping` 均为 L8 / PHP 8.0 既有 API;`require-dev`(testbench `^10` = L12)维持不变,维护侧仍以 L12 为主测目标。在 PHP 8.0.30 + Laravel `^8.54` 下 `composer update --dry-run` 实测可解析(锁定 `symfony/yaml v5.4`、`laravel/framework 8.x`)。

### Fixed

- **L8 兼容(云端推送)**:`CloudClient` 的 4 处 HTTP 调用由 `Http::retry(..., throw: false)` 改为全局 `retry()` 辅助函数包裹 —— `Http::retry()` 的 `throw:` 命名参数是 Laravel 9 才引入的,在 L8 宿主上会 fatal(`Unknown named parameter $throw`)。全局 `retry()` 只在回调抛连接级异常时重试,HTTP 错误响应(4xx/5xx)原样返回供读取,与原 `throw:false` 语义一致且更贴合「5xx/4xx 不在此重试、走幂等下一轮」的既定意图,跨 L8~L12 行为统一。

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
