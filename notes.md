# notes.md — 模型工作记忆（每次开工先读；一条一行，新条目追加在对应分组末尾）

## 当前状态

- 2026-07-09：调优方案定稿于 docs/plans/02-code-tuning.md（修订二，捕获精准度优先）；P1-7 三项决议已拍板，无待决问题；方案文档尚未提交 git。
- master 现有红灯：tests/Feature/HostSafety/NeverThrowsIntoHostTest.php:96 匿名类签名落后于 record() 新签名致 Fatal；tests/Feature/Command/CloudMcpCommandTest.php 有 Pint 违规 —— 方案 P0 先修，修好前 composer quality 必红。

## 已验证的框架事实（都在 vendor/laravel/framework 里核实过，勿凭记忆推翻）

- HttpException 全系（含 5xx）在 Handler::$internalDontReport 名单里，shouldntReport 挡在 reportable 回调之前 —— abort(500) 经 auto_hook 不可见；renderable 回调返回 null 即放行后续渲染，可当只读观察者用。
- Logger::formatMessage 在 fireLogEvent 之前把 Throwable 强转 string —— Log::error($e) 到 MessageLogged 时已无异常对象。
- 异常自带 report() 方法且不返回 false 时，Handler::reportThrowable 直接短路，reportable 回调不执行。
- fatal/OOM 走 HandleExceptions::handleShutdown → report，但保留内存仅 32KB —— 重采集路径（读源码 + 64KB trace 正则 + Yaml::dump）会二次 OOM。
- 调度任务抛异常走 ScheduledTaskFailed + report，不再发 Finished；非零退出码只体现在 ScheduledTaskFinished / ScheduledBackgroundTaskFinished 的 task->exitCode 上（后台任务经 schedule:finish 回填后发后者，两个事件都要接）。
- request() 在 console/队列语境下从容器解析出**空 Request 对象而非 null** —— extractRequest 的 CLI 分支几乎不可达（方案 P2-3：先写测试证实再修）。

## 本仓约束与做法（确认过的）

- PHP 下限 8.0：禁 readonly、enum、foo(...) 一级可调用语法；match / 命名参数 / 构造器提升 / nullsafe / WeakMap 可用。
- pest ^3 要 PHP ≥8.2、testbench ^10 只测 L12 —— 包声明支持 L8~L12/PHP 8.0+ 但验证面窄，下限守护靠方案 P6 的 CI Job B。
- 源码注释是带日期的生产事故档案，重构搬家必须原样保留，严禁顺手删改。
- 「明确不做」清单（方案末节）都是刻意取舍，禁止当优化点：CloudSync 不加 mtime 预筛、record 热路径不加 flock、不复活 index.yaml、不用 Http::retry(throw:)（L8 fatal）、MCP 不引三方 SDK、不 set_error_handler 全局接管、不采 deprecation。
- 任何新采集钩子必须自带防回环设计：本包 safeLog 输出的日志绝不能被自己的日志钩子再采集。
- 云端 intake 契约只增字段、不改既有字段语义（云端按 (project, hash) upsert）。
- 版本号沿 0.1.x 小步发布（当前 0.1.9），不跳 0.2.0 —— 2026-07-09 用户拍板，别按 semver 惯例自作主张升 minor。
- 采集钩子开关口径（2026-07-09 确认）：旁路钩子（log_context / queue_failed / log_message / http_5xx / schedule_exit）独立开关、默认开；reportable 主链由 auto_hook 总控；调度异常走主链不设独立开关，过滤下沉 host 的 dontReport。
- 中文文案全角标点 +「」引号；开源仓（CHANGELOG/注释/文档）不出现私有宿主项目名，只描述技术触发场景。

## 用户偏好

- 代码风格：流程式、简洁易懂、不重复嵌套引用；trait 隐式契约互相引用是明确反感的反模式。
- 不要一次派多个 agent（消耗使用量）；能自己逐文件读就自己读。
- 质疑既有注释与文档：注释描述的是意图不是现实，关键论断去 vendor 源码拿实证。
- commit 不带 Co-Authored-By 尾注（全局 attribution 设置已处理，别手写）。
