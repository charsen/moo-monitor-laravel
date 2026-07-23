# notes.md — 模型工作记忆（每次开工先读；一条一行，新条目追加在对应分组末尾）

## 当前状态

- 2026-07-09：调优方案定稿于 docs/plans/02-code-tuning.md（修订二，捕获精准度优先）；P1-7 三项决议已拍板，无待决问题；方案文档尚未提交 git。
- master 现有红灯：tests/Feature/HostSafety/NeverThrowsIntoHostTest.php:96 匿名类签名落后于 record() 新签名致 Fatal；tests/Feature/Command/CloudMcpCommandTest.php 有 Pint 违规 —— 方案 P0 先修，修好前 composer quality 必红。

## 已验证的框架事实（都在 vendor/laravel/framework 里核实过，勿凭记忆推翻）

- HttpException 全系（含 5xx）在 Handler::$internalDontReport 名单里，shouldntReport 挡在 reportable 回调之前 —— abort(500) 经 auto_hook 不可见；renderable 回调返回 null 即放行后续渲染，可当只读观察者用。
- Logger::formatMessage 在 fireLogEvent 之前把 Throwable 强转 string —— Log::error($e) 到 MessageLogged 时已无异常对象。
- 异常自带 report() 方法且不返回 false 时，Handler::reportThrowable 直接短路，reportable 回调不执行。
- fatal/OOM 走 HandleExceptions::handleShutdown → report，但保留内存仅 32KB —— 重采集路径（读源码 + 64KB trace 正则 + Yaml::dump）会二次 OOM。
- Laravel 8 调度前台命令非零退出只发 ScheduledTaskFinished；Laravel 12 顺序是 Finished → 框架合成普通 Exception → ScheduledTaskFailed → report，同一次失败必须按 task 关联去重；真实任务抛异常没有先发 Finished。后台任务仍经 schedule:finish 发 ScheduledBackgroundTaskFinished。
- request() 在 console/队列语境下从容器解析出**空 Request 对象而非 null** —— extractRequest 的 CLI 分支几乎不可达（方案 P2-3：先写测试证实再修）。

## 本仓约束与做法（确认过的）

- PHP 下限 8.0：禁 readonly、enum、foo(...) 一级可调用语法；match / 命名参数 / 构造器提升 / nullsafe / WeakMap 可用。
- pest ^3 要 PHP ≥8.2、testbench ^10 只测 L12 —— 包声明支持 L8~L12/PHP 8.0+ 但验证面窄，下限守护靠方案 P6 的 CI Job B。
- 源码注释是带日期的生产事故档案，重构搬家必须原样保留，严禁顺手删改。
- 「明确不做」清单（方案末节）都是刻意取舍，禁止当优化点：CloudSync 不加 mtime 预筛、record 热路径不加 flock、不复活 index.yaml、不用 Http::retry(throw:)（L8 fatal）、MCP 不引三方 SDK、不 set_error_handler 全局接管、不采 deprecation。
- 任何新采集钩子必须自带防回环设计：本包 safeLog 输出的日志绝不能被自己的日志钩子再采集。
- 云端 intake 契约只增字段、不改既有字段语义（云端按 (project, hash) upsert）。
- Cloud intake 以逐条 `results` 回执确认：`saved` / `filtered` 立即记账且不重发，retryable `skipped` 只重试自身，non-retryable `skipped` 移入 `cloud-rejected`；聚合计数必须与逐条结果闭合，旧 Cloud 仅在 `skipped=0` 且聚合计数闭合时兼容无 `results` 响应。
- Cloud/Monitor 的逐条回执 consumer-driven contract fixture 镜像在两仓 `tests/Fixtures/cloud-intake-partial-response.json`；Cloud 必须生成该形状，Monitor 必须能严格解析同一形状。
- 版本号沿 0.1.x 小步发布（当前 0.1.12），不跳 0.2.0 —— 2026-07-09 用户拍板，别按 semver 惯例自作主张升 minor。
- 采集钩子开关口径（2026-07-09 确认）：旁路钩子（log_context / queue_failed / log_message / http_5xx / schedule_exit）独立开关、默认开；reportable 主链由 auto_hook 总控；调度异常走主链不设独立开关，过滤下沉 host 的 dontReport。
- 中文文案全角标点 +「」引号；开源仓（CHANGELOG/注释/文档）不出现私有宿主项目名，只描述技术触发场景。
- 宿主日志时间可能使用 `Asia/Shanghai`，而开发机文件时间是 PDT；排查定时任务加载异常时先统一时区。2026-07-22 的 `CloudSync::readAckState()` undefined 发生于源码编辑中间态（日志 14:39 CST = 文件本地 23:39 PDT，最终文件 23:42 保存），当前类反射可见该方法且宿主 `moo:cloud:push --dry-run` 退出码为 0，不是提交后的代码缺口。
- MCP Todo category 契约是四类：`bug`（待分类）、`frontend_bug`、`backend_bug`、`task`；instructions、tool description、`get_todo` 元信息和 README 必须同步，不能继续按旧 `bug|task` 二分类描述。
- partial ack 只保留仍存在于本地 open / resolved 桶的 hash；本地文件被人工清理后也要回写空 ack，不能让 `.acks` 永久残留失效水位。
- MCP stdio 只有通过 `jsonrpc=2.0`、非空字符串 method、结构化 params 校验的无 id 对象才是 notification；顶层非对象、非法 id、畸形 `protocolVersion` 必须分别返回 `-32600` / `-32602`，不能静默丢弃或触发字符串转换 warning。
- Cloud Token 的 MCP 读写面使用独立 `mcp` ability；`runtimes` / `slow_queries` 只授权上报，Chrome 扩展的 `todos` 只授权待办 intake，三者不能互相替代。
- MCP Runtime/Todo 列表以 `offset` 分页，Cloud 返回 `offset / has_more / next_offset`；翻页必须保持相同 `status / limit`。后续页缺少分页回执时 Monitor fail-closed，防止旧 Cloud 忽略 offset 后无限重复第一页。
- 本包调度的 `moo:cloud:*` 非零退出不得写入 runtime 缓冲；推送中断由 Cloud heartbeat 哨兵负责，否则会形成「push 失败 → 采集 push 失败 → 待推数据增加」自反馈。命令内部真正抛出的 Error / RuntimeException 仍须采集，不能隐藏代码根因。
- Cloud summary 返回 200 只证明 Token 有 `runtimes`，不证明独立 `mcp` ability；宿主验收要另跑只读 `list_open_runtimes` / `list_open_todos`。若返回「token 无此权限」，应在 Cloud 侧迁移或给安全 Host Token 授 `mcp`，不能复用上报 ability 绕过。
- 同一 host 用 `artisan --env=XXX` 切换多个 Cloud 项目时，runtime/slow SQL YAML、cursor、partial ack、sync lock、prune 范围和 recorder cache key 必须共用 `StorageScope` 隔离；只隔离 cursor 仍会因同 hash 聚合与回收串项目。
- 多环境 scheduler 必须把父级 `schedule:run/work --env=XXX` 原样传给自动注册的 `moo:cloud:push`；使用 Schedule 参数数组负责转义，环境值进入 command 后 `withoutOverlapping` mutex 会自然隔离。
- Laravel 后台调度依赖独立 `schedule:finish` 释放 `withoutOverlapping` 锁，但该子命令不继承父级 `--env`；带环境 scope 的 Cloud push 必须前台执行让原进程解锁，无 `--env` 的单环境任务继续后台执行。

## 用户偏好

- 代码风格：流程式、简洁易懂、不重复嵌套引用；trait 隐式契约互相引用是明确反感的反模式。
- 不要一次派多个 agent（消耗使用量）；能自己逐文件读就自己读。
- 质疑既有注释与文档：注释描述的是意图不是现实，关键论断去 vendor 源码拿实证。
- commit 不带 Co-Authored-By 尾注（全局 attribution 设置已处理，别手写）。
