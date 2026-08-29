# AGENTS.md

本文件适用于整个 `moo-monitor-laravel` 仓库。规则冲突时依次服从系统/用户指令、当前代理规则、用户批准的任务方案、`NOTES.md`、Cloud 契约和历史 plan。框架行为必须以当前支持版本源码、测试和真实 host 验证，不能仅凭注释或记忆判断。

## 开工顺序与记忆

1. 先完整阅读 `NOTES.md`，其中包含已在 Laravel 源码和生产形态验证的捕获边界、兼容限制与刻意不做事项。
2. 再读 `README.md`、`docs/cloud-api-contract.md`、相关 plan、源码和测试。改采集钩子时同时查对应 Laravel 版本的真实事件/异常链。
3. 修改前读完目标文件和直接调用链。机械性、零语义且范围明确的小修可直接实施；非琐碎或涉及捕获范围、host 副作用、Cloud 数据语义、兼容面的改动先列计划并获用户批准，范围或风险实质变化时重新确认。

- `NOTES.md` 是分组维护的长期记忆，只记录已验证且跨任务可复用的框架事实、项目约束、事故教训和用户决策。
- 新条目追加到对应分组；结论失效时修订原条目并说明证据，不追加相反说法。任务进度、猜测和可从现码直接读出的普通事实不进入 notes。
- 不记录 token、请求敏感值、真实 host 名称或私有地址；本仓公开文案只使用通用技术场景。

## 项目定位

- 本包是 headless Laravel 后端监控采集 SDK：捕获运行时异常和慢 SQL，先写本地缓冲，再增量推送 Moo Scaffold Cloud。
- 本包不提供本地业务页面、不注册业务路由、不拥有 host 业务数据；Scaffold 只消费它的状态/命令，不应把 UI 逻辑反向塞进采集端。
- 运行时支持 PHP/Laravel 范围以 `composer.json` 为准。当前主测试矩阵不等于所有声明版本都已完整验证，改公共 API 必须守住最低 PHP 语法。
- `storage/moo-monitor/` 是可恢复缓冲，不是临时日志。格式、目录、hash、cursor、ack、lock 和环境 scope 均是持久化契约。

## 宿主安全与采集真实性

- 首要原则是监控绝不把异常抛回 host、改变响应、阻塞请求或形成自反馈；所有 hook、记录、日志和推送路径都要 fail-safe。
- “不抛宿主”不等于吞掉本包内部证据：安全日志、命令退出码和测试应保留可诊断原因，但本包日志不能被自己的日志钩子再次采集。
- 采集来源保持现有分工：reportable 主链由 `auto_hook` 总控，log/queue/http 5xx/schedule 等旁路按独立开关；不要为补漏制造双计。
- 沿用 Laravel `dontReport`/host 过滤，未捕获的 bootstrap 早期异常、自带 `report()` 的短路边界和 deprecation 等已知盲区要如实说明，不能宣称全覆盖。
- 相同问题的 hash/聚合必须稳定；修改 source、message、stack、binding、用户或请求提取时验证不会造成指纹 ping-pong、敏感信息泄漏或数量失真。
- fatal/OOM 走轻量路径，不能在保留内存极小时读取大量源码、正则重扫长栈或构造重 payload。
- 慢 SQL 记录不得改变查询；bindings 填充和请求/用户上下文都需脱敏，跨 console/queue/HTTP 语境不能凭空制造请求身份。

## 本地缓冲、环境隔离与 Cloud

- 记录先落本地；Cloud/网络失败不得影响业务请求，也不得丢掉未确认数据。
- 同一 host 的 `artisan --env=XXX` 环境必须共同隔离 YAML、cursor、partial ack、sync lock、prune 范围、recorder cache 和自动调度命令；不能只隔离其中一项。
- Cloud intake 以逐条 `results` 为确认真相：saved/filtered、retryable skipped、non-retryable skipped 各有不同去向；聚合计数必须和逐条回执闭合。
- consumer-driven contract fixture 在 Monitor 与 Cloud 两仓镜像。改变字段、ability、分页或 skip reason 时必须同步验证生产者与消费者；契约只增字段，不静默改变旧字段语义。
- push、prune、ack 和 recovery 涉及同一数据桶时保持锁与原子写；手工删除本地数据后也要清理失效水位，避免幽灵 ack。
- 本包自己的 `moo:cloud:*` 调度失败不反向生成 runtime 风暴；命令内部真正抛出的代码异常仍要可见。
- Token ability 分离：runtime/slow query 上报、MCP 读取、todo intake 不能互相替代。日志和错误信息不得回显 token。

## 兼容性与代码约束

- 最低 PHP 版本允许的语法是硬约束；禁止 readonly、enum 等超出下限的运行时代码，即使 Laravel 12 测试通过。
- 多 Laravel 版本的事件顺序和方法签名不一致。新增 hook 先写版本矩阵测试或最小 host 冒烟，不通过条件分支猜兼容。
- 源码中的生产事故注释承载非显而易见约束；重构搬迁应保留其含义和证据，不顺手清理。
- 保持流程式、易读、少隐式 trait 契约的风格。既有明确“不做”项没有新证据不得重启设计。
- 公共 config/env、命令、文件格式、Cloud payload 和 MCP JSON-RPC 都是外部契约；改名/删除需迁移和兼容策略。

## 验证与交付

- 本仓采用双 Git 远端：Gitee 主仓固定为 `origin`，GitHub 公共仓固定为 `github`。禁止恢复 GitHub Actions、Gitee 或第三方平台的定时/自动/双向镜像同步；自动镜像的凭据、网络和 ref 覆盖链路不稳定，不能作为交付真相。
- commit、分支、tag 和 release 必须按 Gitee `origin` → GitHub `github` 的顺序分别推送；每次推送后用 `git ls-remote` 对照两端目标 branch、tag 对象及 peeled commit。任一端失败或 refs 不一致时不得宣称交付完成，也不得用 `git push --mirror` 扩大影响范围。
- 文档-only 至少运行 `git diff --check`，并核对 README、config 和 Cloud 契约的同款描述。
- 代码改动开发中按需先跑目标 Pest；最终代码状态执行一次 `composer quality`，它已聚合 Composer 校验、lint 和测试，不再重复运行 `composer test`，失败诊断时才拆分。需要格式化时用 Pint 显式处理本任务文件并复核 diff，不对全部脏文件运行写入式 `composer format`。
- 改 Laravel 兼容面时追加低版本安装/启动冒烟，不能只凭 Testbench 10 宣称 Laravel 8～12 全部通过。
- 改 Cloud/MCP/partial ack 时用双方同一 fixture 验证；改 scheduler 或多环境 scope 时至少覆盖单环境、多 `--env`、锁竞争和失败恢复。
- 不主动 commit、push、bump、tag 或发布。提交前展示完整 diff 与真实验证结果并取得用户明确确认；无法验证的 host/Cloud/低版本面必须单列风险。
