# Plan 02：捕获精准度审计与调优方案

> 状态：待执行（执行者：Opus 4.8）
> 日期：2026-07-09（修订二 —— 全面重写：修订一以架构整洁为主线，被既有注释想定；
> 修订二以「监控使命」为第一基准重审：**一个真实 Laravel 宿主里，错误有几条路能绕过这个 monitor？**
> 答案是至少五条，全部在 vendor 框架源码里拿到了实证。捕获补漏升为最高优先级，
> 架构整洁降为后置阶段。）
> 审查范围：src/ 全部 18 个文件 + config + tests 结构 + vendor/laravel/framework 关键路径实证

## 0. 基准与结论

本包的使命只有一句话：**宿主发生的异常和错误，要精准地被检测并上报云端**。
「精准」拆成两半：**不漏报**（覆盖面）与**不失真**（计数/上下文/归因的真实性）。

以此为基准的结论：现有三个采集钩子（reportable / log_context / queue_failed）只覆盖
「纪律良好」的错误路径；真实宿主中大量错误走的是不规范路径，全部漏掉。
下面的覆盖矩阵是本方案的核心资产，P1 按它逐项补漏。

### 覆盖矩阵（逐项在 vendor 中验证过，非推断）

| # | 错误发生路径 | 现状 | 证据 |
|---|---|---|---|
| 1 | 未捕获异常冒泡到 Handler（HTTP / console） | ✅ reportable 覆盖 | — |
| 2 | 显式 `report($e)` | ✅ | — |
| 3 | `Log::error('…', ['exception' => $e])`（规范形态） | ✅ log_context 覆盖 | — |
| 4 | `Log::error($e)` / `Log::error('失败: ' . $e->getMessage())` | ❌ **全漏** | Logger::formatMessage 在 fireLogEvent **之前**把 Throwable 强转 string（vendor Illuminate/Log/Logger.php:181-193、270-277），context 无 exception 键 |
| 5 | `abort(500/502/503)` / 任何 HttpException 5xx | ❌ **全漏** | HttpException::class 在 `$internalDontReport`（vendor Foundation/Exceptions/Handler.php:153），且 `shouldntReport` 在 reportable 回调之前（Handler.php:357-366）—— 本包 `RuntimeErrorRecorder::shouldReport()` 的「5xx 仍记」分支经 auto_hook **不可达**，注释给了虚假信心 |
| 6 | 自带 `report()` 方法的自定义异常（Laravel 官方文档推荐模式） | ❌ 漏 | `$e->report()` 存在且不返回 false 时直接 return，不跑 reportCallbacks（Handler.php:376-389） |
| 7 | OOM / 超时等 fatal | ⚠️ 名义覆盖、实际大概率自爆 | shutdown 兜底只释放 32KB 保留内存（vendor Foundation/Bootstrap/HandleExceptions.php:43、233-240）；本包采集路径要 file() 读源码 + 64KB trace 五连正则 + payload 递归脱敏 + Yaml::dump，OOM 语境下极易二次耗尽，record() 的 catch 也救不回 |
| 8 | 队列 job 最终失败 | ✅ JobFailed 钩子 | — |
| 9 | 队列 job 中途重试的异常 | ✅ Worker 每次 attempt 都 report | — |
| 10 | 调度任务抛异常 | ✅ | ScheduleRunCommand catch → report（vendor ScheduleRunCommand.php:209-213） |
| 11 | 调度任务非零退出码（不抛异常，exec 型） | ❌ 漏 → P1-7 补采（已拍板） | 同上文件 :215 只用 exitCode 判成败，不上报 |
| 12 | PHP warning / notice | ✅ | 框架 handleError 转 ErrorException 抛出，归入路径 1 |
| 13 | MonitorProvider 注册之前的 bootstrap 异常 | ❌ 已知边界，不修 | 钩子尚未挂上；修复收益/复杂度不成比例 |
| 14 | 慢 SQL | ✅ 但缺 connection 名等上下文 | QueryExecuted::$connectionName 现成未采 |

### 失真清单（采到了，但数据不真）

| # | 失真点 | 根因 |
|---|---|---|
| A | 热错误计数封顶在 daily_cap（默认 10），实际发生 10 万次云端仍显示 10 | 冻结机制把「写盘节流」和「计数真实性」绑在一起牺牲了 |
| B | `tagSource` 绕过 daily_cap 冻结反复刷盘；meta.source 在 refresh 与 tagSource 之间反复升降级 | 优先级语义拆在两个类、且 tagSource 无冻结闸 |
| C | console / 队列语境异常被记成 `GET http://…` 而非 `CLI` | `request()` 在容器里解析出**空 Request 对象而非 null**，extractRequest 的 CLI 分支几乎不可达 |
| D | 含单引号/反斜杠的 SQL binding 拼进 sql_last 后引号结构损坏，值侧脱敏正则可能错位漏脱敏 | fillBindings 不转义 |
| E | 异常链（`getPrevious()`）完全不采，包装异常丢根因（QueryException 里的 PDOException 等） | extractException 无 previous 处理 |
| F | app_frames 只认 `app/`、`routes/` 前缀，Modules/、src/、database/ 布局的宿主调用栈为空 | 前缀硬编码 |

---

## 硬约束（每一步都不得违反）

- **PHP 下限 8.0**：禁 readonly 属性、enum、一级可调用语法 `foo(...)`；
  match / 命名参数 / 构造器提升 / nullsafe / WeakMap 可用。
- **五条运行时不变量**：
  ① 采集链路永不向宿主抛异常；② 桶目录是 status 唯一真源；③ 写盘原子（tmp+rename）；
  ④ 推送任一批失败即停、游标不前进，`saved === count(records)` 才算成功；
  ⑤ deleted 桶不上云。
- **新增第六条：任何新采集钩子必须自带防回环设计** —— 本包自己 safeLog 出的
  error 级日志绝不能被自己的日志钩子再采集（见 P1-1 的重入闸）。
- 注释是带日期的事故档案，重构搬家原样保留；中文全角标点 +「」；
  开源仓不出现私有宿主项目名。
- **每阶段独立 commit，`composer quality` 全绿后进下一阶段**；改行为先补测试锁行为。
- 云端 intake 契约不破：记录形状只增字段不改既有字段语义（云端按 (project, hash) upsert，
  新增可选字段兼容）。

---

## P0：止血 —— master 当前测试是红的

1. `tests/Feature/HostSafety/NeverThrowsIntoHostTest.php:96` 匿名子类签名落后于
   `RuntimeErrorRecorder::record()` 新签名（多了 `$source`/`$meta`）→ Fatal，套件中断。
   同步签名并透传。
2. `tests/Feature/Command/CloudMcpCommandTest.php` 有 Pint 违规 → `composer format`。

**验收**：`composer quality` 全绿。

---

## P1：捕获补漏（本方案核心，按矩阵逐项）

### 钩子开关口径（统一原则，2026-07-09 用户确认）

所有**旁路采集钩子**独立开关、默认开启：`log_context_hook` / `queue_failed_hook`
（已有）、`log_message_hook`（P1-1）、`http_5xx_hook`（P1-2）、
`schedule_exit_hook`（P1-7①）。reportable 主链由 `auto_hook` 总控。
**调度任务抛异常不设独立开关** —— 它走框架主动 report 的 reportable 主链
（vendor ScheduleRunCommand.php:212），单独关闭需回溯栈探测调度上下文，侵入且脆弱；
不需要的宿主用 Laravel 原生 dontReport 过滤（「过滤下沉 host 层」是本包既定原则，
见 ExceptionDispatcher 头注释）。

### P1-1 字符串化异常进日志（矩阵 #4）—— 最大的真实漏洞

业务代码里 `Log::error($e)`、`Log::error('xx失败: ' . $e->getMessage())`、
`catch (Throwable $e) { Log::error($e->getTraceAsString()); }` 极其常见，
现有 log_context 钩子只认 `context['exception'] instanceof Throwable` 一种规范形态。

**改法**：MessageLogged 钩子扩展 —— level 命中且 context 无异常对象时，
合成一条「日志错误」记录进同一条 record 管道：

1. 新建哨兵异常 `src/LoggedErrorMessage.php`（extends RuntimeException）：
   构造器接收 message + 调用点 file/line，**在构造器内直接给 `$this->file` /
   `$this->line` 赋值**（Exception 的这两个属性是 protected，子类可写）——
   这样整条 record() 管道（hash 按调用点聚合、source_snippet 取调用点源码、
   脱敏、daily_cap、桶管理）零改动复用。
2. 调用点定位：MessageLogged 是同步事件，listener 内 `debug_backtrace` 找第一个
   应用帧即日志调用点（复用/参照 `SqlSlowListener::firstAppFrame()` 的
   「只看 file path、不看 class」与 vendor 判定注释，src/Recorder/SqlSlowListener.php:106-134）。
3. source 定为 `log_message`，meta 带 `log_level` + `log_message`（截 500，与现有
   log_context 一致）；`ExceptionDispatcher::sourcePriority()` 加入该来源（建议 15，
   低于 log_context —— 有真异常对象的信息量更高）。
4. **防回环闸（必须，硬约束第六条）**：本包 `SafelyLogs::safeLog()` 与
   `logWriteFailure()` 会写 warning/error 日志，若被本钩子采集会形成
   「写盘失败 → error 日志 → 采集 → 又写盘失败」死循环。双保险：
   ① listener 入口 `static $recording` 重入闸（进入置 true，finally 复位）；
   ② safeLog 统一给 context 加 `['moo_monitor_internal' => true]` 标记，钩子见标记即跳过。
5. 配置：`exception.log_message_hook`（默认 true —— 精准优先；噪音由
   daily_cap / max_open 既有闸门兜底）+ 消息为空/纯空白跳过。

**测试**：`Log::error('boom: db timeout')` → 落盘记录 hash 稳定、file:line 指向调用点、
snippet 是调用点源码；`Log::error($e)`（Stringable 形态）也被采集；
safeLog 带内部标记的日志不被采集（回环测试必须有）。

### P1-2 HttpException 5xx（矩阵 #5）

`abort(500/502/503)`、第三方包抛的 5xx 全部不可见，而这类恰是「服务对外已经在冒烟」的信号。

**改法**：auto_hook 时同时注册 **renderable 观察者**：

```php
$handler->renderable(function (HttpException $e, $request) {
    if ($e->getStatusCode() >= 500) {
        $this->app->make(ExceptionDispatcher::class)->dispatch($e, source: 'http_5xx');
    }
    return null;   // 关键：返回 null 即放行后续渲染，不影响宿主响应
});
```

已在 vendor 验证：renderViaCallbacks 对 null 返回值继续走默认渲染
（Handler.php:712-724）。WeakMap 防双计天然兼容（若宿主自行把 HttpException
移出 dontReport，reportable 先记、renderable 到达时已去重）。
配置开关 `exception.http_5xx_hook`（默认 true，与钩子开关口径一致）。

**连带修正**：`RuntimeErrorRecorder::shouldReport()`（src/Recorder/RuntimeErrorRecorder.php:216-227）
的注释改为如实描述：「该分支只对 log_context / 手动 dispatch 等旁路生效；
reportable 路径的 HttpException 已被框架 internalDontReport 拦下，5xx 由 renderable
观察者补采」—— 消灭虚假信心。

**测试**：testbench 路由里 `abort(503)` → 落盘 + status 500 以下不落盘 + 响应体不变。

### P1-3 自带 report() 的自定义异常（矩阵 #6）—— 如实分级，部分缓解

框架在 reportable 回调**之前**短路（Handler.php:380-383），无干净钩子可完全兜住。
缓解组合：HTTP 语境冒泡到渲染层的，P1-2 的 renderable 观察者可见（renderable 不受
report 短路影响）；其 report() 内若写日志，P1-1 可见。剩余暴露面（console/queue 下
self-reporting 且不写日志）接受为已知边界。

**动作**：README「采集范围与边界」一节如实写明（连同矩阵 #13 一并归档为已知边界）。
不写代码。

### P1-4 OOM / fatal 的 lean path（矩阵 #7）

**改法**：`record()` 入口探测「危险语境」：
`$e instanceof \Symfony\Component\ErrorHandler\Error\FatalError`
（Laravel shutdown 兜底的产物），或 `memory_get_usage(true) / memory_limit > 0.9`
（memory_limit 解析成字节，-1 视为不触发）。命中则走 **lean path**：

- 跳过 source_snippet（file() 整读）、payload（递归脱敏）、trace 正则五连
  （getTraceAsString 直接截 4KB，只做 maskSecrets 单遍）；
- exception.message 截 512；request 只留 method/url（url 仍必须 maskUrl —— 脱敏不降级）；
- 记录 `meta.lean = true`，云端可识别「降级采集」。

**测试**：构造 FatalError 实例走 record → 产出最小记录且各字段仍脱敏；
常规异常不受影响。（真实 OOM 无法在测试中可靠复现，按类型驱动测试并在注释中说明。）

### P1-5 异常链 previous（失真 E）

`extractException()` 增加 `previous` 数组（最多 3 层，每层
`{class, message(同款双层脱敏), file(relPath), line}`）。hash 仍按最外层算（聚合稳定性不变）。
refresh 路径同步覆盖。云端契约只增字段。

**测试**：三层包装异常 → previous 依序完整、第 4 层截断、message 已脱敏。

### P1-6 慢 SQL 补 connection（矩阵 #14）+ 调用栈前缀配置化（失真 F）

1. `SqlSlowListener::handle()` 把 `$event->connectionName` 传给 recorder，
   落 `at.connection`；deriveRow 一并带上。
2. 新配置 `runtime.app_frame_prefixes`（默认 `['app/', 'routes/']`），
   `extractTrace()` 的 app_frames 过滤改读配置；config 注释举例 Modules/、src/、database/。

### P1-7 已拍板决议（2026-07-09 用户确认，按此执行、不再询问）

**① 调度任务非零退出码：采。**
监听 `Illuminate\Console\Events\ScheduledTaskFinished` 与
`ScheduledBackgroundTaskFinished`（后台任务经隐藏命令 `schedule:finish` 回填退出码后
发后者，两个事件都要接）。exitCode 取自 `$event->task->exitCode`，
`is_int($code) && $code !== 0` 才合成记录（null 视为未知不采）。实现要点：

- 复用 P1-1 的哨兵异常方案（同类或姊妹类），message 形如
  「调度任务退出码非零：{command 摘要} (exit {code})」——
  normalizeMessage 会把数字归一为 N，退出码不同不会裂 hash，天然按 command 聚合；
- source 定为 `schedule_exit`，meta 带 command / exit_code / runtime（秒）；
- 与异常路径不重叠已在 vendor 验证：回调任务抛异常走 ScheduledTaskFailed 分支
  （ScheduleRunCommand.php:209-213），不会再发 Finished；
- 已知代价（接受）：exec 型子进程内若发生真实异常，子进程自身会报一条 runtime 记录，
  父进程再报一条 schedule_exit —— 两条 hash 不同并存，云端可按 source 区分；
- 配置开关 `exception.schedule_exit_hook`（默认 true）。

**② daily_cap 溢出回填接受 cache best-effort。**
cache 后端不可用/被清时溢出计数丢失、退回现状的偏低行为，不引入本地文件计数器
（热路径加写盘 IO 违背「采集绝不拖垮宿主」）。P2-1 按此实现，不做降级兜底。

**③ log_context level 白名单只做配置化，默认值不放宽。**
新配置 `exception.log_context_levels`，默认
`['error', 'critical', 'alert', 'emergency']`（行为与现状完全一致）；
P1-1 的 log_message 钩子复用同一配置。config 注释说明：想兜 warning 级的宿主自行加。

---

## P2：失真修复

### P2-1 daily_cap 与计数真实性解耦（失真 A）

现状：达 cap 后 record() 直接 return，count / last_seen / daily.count 全部冻结 ——
节流目标（不被 moo:cloud:push 每分钟重推）是对的，但把计数真实性一起牺牲了。

**改法（溢出计数回填）**：
1. 冻结期 record()：`cache()->increment("moo-monitor:{type}:overflow:{hash}")`
   （TTL 设到次日凌晨 + 1h），仍返回 hash、不写盘 —— 推送节流语义不变；
2. 次日该 hash 首次通过 cap 闸写盘时：读出 overflow 增量并清 key，
   `count += overflow + 1`、daily 归一重计 —— 云端在次日第一轮 push 拿到真实累计；
3. cache 不可用 → 维持现状（计数偏低），与 ManagesBucketedRecords 头注释声明的
   best-effort 计数语义一致（该注释同步补一句溢出回填的说明）。
   此取舍已经 P1-7 决议②确认，不做本地文件计数器等降级兜底。

**测试**：cap=2，当天打 5 次 → 盘上 count=2；次日再打 1 次 → count=6（2+3 溢出+1 新增）。

### P2-2 tagSource 绕过冻结 + source 优先级 ping-pong（失真 B）

1. source 优先级表收口一处（建议 RuntimeErrorRecorder 私有常量 + `preferSource()`；
   ExceptionDispatcher 复用同一语义，注释注明真源位置，
   新增 `log_message`/`http_5xx`/`schedule_exit` 三级）；
2. `runtimeMeta()` 的 source 字段只升不降（refresh 不得把 queue_failed 降回 log_context）；
3. `tagSource()` 两道闸：来源集合无变化不写盘；`dailyCapReached()` 不写盘
   （meta 变化随次日回填写盘一起落）。

**测试**：冻结后 tagSource 不改 mtime/updated_at；先 queue_failed 后 log_context，
source 保持 queue_failed；sources 并集正确。

### P2-3 console 语境 request 误标（失真 C）

三处 `$request ??= function_exists('request') ? request() : null;`
（src/ExceptionDispatcher.php:61、RuntimeErrorRecorder.php:81、SqlSlowRecorder.php:69）
在 artisan / 队列 worker 下解析出空 Request 对象，`method: 'CLI'` 分支不可达。

**改法**：先写测试证实（testbench console 语境断言落盘 request.method），
证实后统一改为「显式传入优先；否则 `app()->runningInConsole()` 时取 null」，
保持 try 包裹（不变量①）。若测试推翻推断（返回 null），记录结论关闭本项。

### P2-4 fillBindings 转义（失真 D，兼脱敏安全）

`formatBinding()`（src/Recorder/SqlSlowListener.php:93-95）字符串 binding 改为
`str_replace(['\\', "'"], ['\\\\', "\\'"], $binding)` 后包引号 —— 与
maskSensitiveSql 的引号串正则认可的转义形态一致，堵住「畸形引号让脱敏正则错位」。

**测试**：含单引号/反斜杠的 binding → sql_last 引号结构合法；
`password` 列的值即使含引号也被替成 `***`。

### P2-5 auth guard 硬编码 ×2

`['admin', 'user', 'web']` 写死在 RuntimeErrorRecorder.php:381 与 SqlSlowRecorder.php:292，
宿主用 api / sanctum / 自定义 guard 时 user 永远采不到（这也是覆盖面问题）。
新配置 `runtime.auth_guards`（默认不变），与 P4 的去重合并后只改一处。

### P2-6 CloudClient 违反自declared「纯传输」契约

docblock（src/Cloud/CloudClient.php:14-16）说不读 config，`heartbeatMeta()`（:191-211）
读了三段 config。meta 组装挪出（建议 `Cloud/HeartbeatMeta::collect()` 静态助手，
`heartbeat(array $meta = [])` 接收传入），heartbeat 请求体形状不变。

---

## P3：新增覆盖面的守卫 —— CaptureMatrixTest

新建 `tests/Feature/Capture/CaptureMatrixTest.php`：**覆盖矩阵的每一行 ✅ 一条端到端断言**
（触发该路径 → 断言落盘记录的 source / hash / 关键字段）。矩阵行 4、5（P1 补的）
完成后加入。此文件是「覆盖面契约」——今后任何人改采集钩子，这里先红。
文件头注释附上本方案的矩阵表缩略版与 vendor 证据行号。

---

## P4：架构收口（修订一的核心，降级为后置 —— 理由：先把「采什么」做对，再谈「怎么组织」）

要点保持修订一结论，压缩表述：

1. **三个存储 trait（ManagesBucketedRecords / WritesBucketedYaml / TracksDailyCap）
   合并为抽象基类 `BucketedYamlRecorder`**：现状靠隐式契约互相引用
   （`$this->config` / `$this->basePath` / 跨 trait 方法 / CACHE_OPEN_COUNT 常量），
   读一个方法跳 3~4 个文件。基类持有状态、声明抽象 `deriveRow()`；两个 recorder
   逐字重复的 record() 骨架（findBucket → 复发搬桶 → cap 闸 → 满闸 → 写盘 → 失败诊断）
   提为模板方法 `persistAggregated(string $hash, callable $build, callable $refresh): ?string`。
   注意 P1/P2 的新逻辑（lean path、溢出回填、tagSource 闸）落进基类时保持单一实现。
2. **脱敏独立成 `SensitiveMasker` final class**（构造器注入 mask_keys）：
   现 trait 名 `MasksSensitiveUrl` 名不副实（实管 URL/SQL 值侧/INSERT 列/JWT/Bearer 四类），
   且靠 `$this->config` 隐式契约。独立后可纯单测。
3. **残余重复去重**：extractRequest / extractContext / extractUserName（现误放在
   桶管理 trait，ManagesBucketedRecords.php:262-274）/ logWriteFailure / 截断助手，
   全部收进基类单份。
4. **ExceptionDispatcher 压平三层同构 try/catch**（dispatch 外层总闸保留，
   dispatchRuntime / tagRuntimeSource 的内层删除）。

**验收**：既有测试 + P3 的 CaptureMatrixTest 不改断言全绿（纯结构重构不改行为）。

---

## P5：流程式可读性（保持修订一结论，压缩）

1. `MonitorProvider::boot()` 拆为 publishesConfig / listenSlowQueries /
   hookExceptionReporting / scheduleCloudPush 四个私有方法，boot 变清单式；
   P1-1 / P1-2 的新钩子挂进 hookExceptionReporting。
2. `CloudMcpCommand`（520 行）拆传输层（McpLoop：stdin 循环 + JSON-RPC 编解码）
   与工具层（CloudToolset：定义 + handler），命令类只剩装配。
3. `CloudSync::result()` 十参数数组 → `SyncResult` final class（构造器提升，
   **不加 readonly**，PHP 8.0）；评估改动面大于收益时可降级为数组 + shape 注解，
   commit message 说明取舍。
4. 注释漂移清理：TracksDailyCap::today() 还说 `date('c')`（nowIso 已毫秒化）；
   全仓 grep 已退役概念，只改「仍以现状口吻描述」的，档案口吻的保留。

---

## P6：质量基建

1. **CI（GitHub 镜像跑 Actions）**：
   Job A = PHP 8.3 / testbench 10 跑 `composer quality`；
   Job B = PHP 8.0 + `composer update --prefer-lowest --no-dev` + `php -l` 全量 +
   最小 boot 冒烟（require autoload、实例化 Provider、调 version()）。
   现状零 CI 且「声明支持 L8~L12 / PHP 8.0+，实际只测 L12 / PHP 8.2+」
   （testbench ^10、pest ^3 的版本地板所致）—— 声明宽、验证窄，Job B 至少守住语法与依赖下限。
2. **phpstan level 6** 进 `composer quality`（存量噪音进 baseline，真 bug 顺手修）。

---

## 明确不做（前人踩坑后的刻意取舍 + 本次新增，禁止「优化」）

1. CloudSync 读盘不加 mtime 预筛（迁移/复制保留旧 mtime 会漏推，见 CloudSync.php:228-230）。
2. record() 热路径不加 flock（锁竞争违背「采集绝不拖垮宿主」，见 ManagesBucketedRecords.php:22-27）。
3. 不复活 index.yaml 类聚合索引（多端冲突的设计原罪）。
4. CloudClient 的 retry 不改 `Http::retry(throw:)`（L8 会 fatal，见 CloudClient.php:92-93）。
5. MCP server 不引第三方 SDK（生产机常驻进程，依赖面最小化是刻意的）。
6. **不用 set_error_handler 全局接管 PHP 错误**：框架已把 warning/notice 转
   ErrorException（矩阵 #12 已覆盖），叠一层自己的 handler 只会与宿主/框架争抢且引入
   兼容矩阵，收益为零。
7. **不采 deprecation**：量大、非错误、Laravel 有独立 deprecations 通道，采集只制造噪音
   （与「精准」相反）。

---

## 执行顺序与提交切分

| 阶段 | 内容 | commit 数 | 备注 |
|---|---|---|---|
| P0 | 修红灯 | 1 | 先让 quality 变绿 |
| P1 | 补漏 6 项（P1-3 只写文档；P1-7 决议已拍板，直接执行） | 6~7 | 每项先测后码 |
| P2 | 失真 6 项 | 6 | 同上 |
| P3 | CaptureMatrixTest | 1 | 之后它是所有采集改动的守卫 |
| P4 | 架构收口 | 3~4 | 靠 P3 + 既有测试兜底 |
| P5 | 可读性 | 3~4 | — |
| P6 | CI + phpstan | 2 | — |

每 commit 前 `composer quality`；P1/P2 完成后在任一已接入本包的私有宿主项目
开发环境触发各路径 + `moo:cloud:test` 做端到端验证。全部完成更新 CHANGELOG
（技术性描述触发场景，不出现私有项目名）。**版本号沿 0.1.x 小步发布**（当前 0.1.9，
按阶段完成度发 0.1.10、0.1.11…；不跳 0.2.0 —— 2026-07-09 用户拍板），
云端契约向后兼容。
