# moo-monitor-laravel

Laravel 后端监控采集 SDK，用来把项目里的 **运行时异常** 和 **慢 SQL** 上报到 [moo-scaffold-cloud](https://gitee.com/charsen/moo-scaffold-cloud) 集中查看、告警和处理。

这个包是一个 **headless 采集端**：不提供本地页面、不注册业务路由，只负责采集数据、写入本地缓冲目录，再推送到云端。

## 适用场景

- Laravel 项目需要统一收集后端异常。
- 想知道哪些 SQL 超时、在哪个文件和行号触发。
- 多个项目需要在同一个云端控制台查看问题、告警和处理状态。
- 从 `moo-scaffold <= 3.8` 升级后，需要继续沿用原来的监控数据。

## 业务功能

| 功能 | 说明 |
| --- | --- |
| 运行时异常监控 | 自动接入 Laravel 异常上报链路，记录异常类型、消息、文件行号、请求 URL、用户、调用栈和源码片段；并补采 `abort(500/502/503)` 等未进入上报链路的 HttpException 5xx。 |
| 日志异常兜底 | 自动捕获 `Log::error(..., ['exception' => $e])`，以及 `Log::error($e)` / `Log::error('失败: '.$e->getMessage())` 这类「异常已被字符串化、只写日志」的形态，避免队列 failed / 业务 catch 漏进云端。 |
| 队列失败捕获 | 监听 Laravel `JobFailed` 事件，把 connection、queue、job name、attempts 等失败上下文带进 runtime。 |
| 慢 SQL 监控 | 监听 Laravel `QueryExecuted` 事件，超过阈值的 SQL 会被记录，包含执行耗时、SQL 内容、触发位置和请求信息。 |
| 相同问题聚合 | 相同异常或相同慢 SQL 会按 hash 聚合，累计出现次数，避免同一个问题刷屏。 |
| 敏感信息脱敏 | 对 password、token、secret、authorization 等常见敏感字段做脱敏处理。 |
| 本地缓冲 | 数据先写入 `storage/moo-monitor/`，云端或网络异常不会影响业务请求。 |
| 多环境隔离 | 同一 host 通过 `artisan --env=XXX` 连接多个 Cloud 项目时，自动隔离 YAML、游标、partial ack 与同步锁。 |
| 云端推送 | 通过 `moo:cloud:push` 增量推送到 moo-scaffold-cloud，云端负责查看、告警和处置。 |
| AI / MCP 辅助处理 | 可通过 `moo:cloud:mcp` 让 Claude Code / Codex 等工具读取云端 runtime 错误和待办，并回写处理状态。 |
| 旧版迁移 | 支持从 `moo-scaffold <= 3.8` 的本地监控目录迁移到新目录。 |

## 采集范围与边界

监控的使命是「宿主发生的异常和错误，被精准检测并上报云端」。下面如实列出**覆盖的错误路径**与**已知边界**，接入方据此判断盲区、按需在 host 侧补齐。

**覆盖的错误路径**

- 未捕获异常冒泡到框架异常处理链（HTTP / console）；
- 显式 `report($e)`；
- `Log::error(..., ['exception' => $e])`（规范形态）与 `Log::error($e)` / `Log::error('...'.$e->getMessage())`（字符串化形态）；
- `abort(500/502/503)` 与第三方包抛出的 HttpException 5xx（只读观察，不改宿主响应）；
- 队列任务最终失败（`JobFailed`）与每次重试 attempt 抛出的异常；
- 调度任务抛出的异常；
- 调度任务非零退出码（exec 型任务不抛异常、仅以退出码表示失败，监听 `ScheduledTaskFinished` / `ScheduledBackgroundTaskFinished`；Laravel 12 随后合成的普通异常会按同一 task 去重）；
- PHP warning / notice（框架已转 `ErrorException` 抛出，归入第一条）；
- 慢 SQL（超过阈值的查询）。

**已知边界（刻意不覆盖或暂无干净钩子）**

- **自带 `report()` 方法的自定义异常**：Laravel 在 `reportable` 回调**之前**就执行异常自身的 `report()` 并短路（框架设计），没有干净钩子能完整兜住。缓解：HTTP 语境下冒泡到渲染层的仍可被 5xx 观察者看到；其 `report()` 内若写了 error 日志，日志兜底也能看到。剩余暴露面（console / 队列语境下 self-reporting 且不写日志）作为已知边界。
- **服务提供者注册之前的 bootstrap 阶段异常**：采集钩子此时尚未挂上，属框架启动早期，修复收益与复杂度不成比例，不覆盖。
- **deprecation 通知**：量大、非错误，Laravel 有独立的 deprecations 通道，采集只会制造噪音，故不采集。

过滤（`dontReport` / 异常类白名单）沿用 Laravel 原生机制下沉到 host 层，本包不重复实现。

## 环境要求

- PHP 8.0+
- Laravel 8.54 / 9 / 10 / 11 / 12
- 依赖：`laravel/framework`、`symfony/yaml`

> 运行时代码只用 Laravel 8+ 通用 API，对 L8 宿主零行为差异。维护侧以 Laravel 12（`orchestra/testbench ^10`）为主测目标跑完整功能测试；跨版本兼容以安装解析 + 最小 Laravel 应用启动验证守护（确保服务提供者、命令注册和基础配置在 Laravel 8–12 都能正常加载），发版前另可用 `composer smoke:lower` 对低版本宿主做一次接入冒烟（见「开发」）。

## 安装

```bash
composer require charsen/moo-monitor-laravel
```

如果包还没有发布到 Packagist，需要先在宿主项目的 `composer.json` 增加仓库源：

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "git@gitee.com:charsen/moo-monitor-laravel.git"
    }
  ]
}
```

本地联调可以使用 path repository：

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../moo-monitor-laravel"
    }
  ]
}
```

如果项目已经安装 `moo-scaffold >= 3.9`，通常不需要单独安装本包，scaffold 会自动依赖它。

## 快速接入

在宿主项目 `.env` 中配置云端推送：

```env
MOO_MONITOR_CLOUD_ENABLED=true
MOO_MONITOR_CLOUD_TOKEN=moo_xxxxxxxx

# 私有部署时再配置，默认是 https://c.mooeen.com
# MOO_MONITOR_CLOUD_URL=https://c.mooeen.com
```

Token 在 moo-scaffold-cloud 的「接入 Token」页面生成：推送需要 `runtimes` 和 `slow_queries` 能力；若还要运行 `moo:cloud:mcp`，同一枚私密 Host Token 还需开启 `mcp` 能力。`mcp` 不能与 Chrome 扩展的 `todos`、浏览器端 `frontend_errors` 或 CI 的 `sourcemaps` 混用。

运行时异常默认开启：

```env
MOO_MONITOR_RUNTIME_ENABLED=true
```

慢 SQL 默认关闭，需要时开启：

```env
MOO_MONITOR_SQL_SLOW_ENABLED=true
MOO_MONITOR_SQL_SLOW_THRESHOLD_MS=100
```

同一份 Laravel 代码若通过多份 `.env.XXX` 对应多个 Cloud 项目，保持默认配置即可：

```bash
php artisan moo:cloud:push --env=PROJECT_A
php artisan moo:cloud:push --env=PROJECT_B
```

若由 Laravel Scheduler 自动推送，请让每个项目的 scheduler 进程带上对应 `--env`；本包生成的
`moo:cloud:push` 子命令会继承该值，并为不同环境使用独立的 `withoutOverlapping` 锁。带
`--env` 的 scoped push 会在 scheduler 原进程前台执行，确保完成后释放同一环境的锁；未指定
`--env` 的普通单环境任务继续后台执行：

```bash
php artisan schedule:work --env=PROJECT_A
php artisan schedule:work --env=PROJECT_B
```

默认 `MOO_MONITOR_STORAGE_SCOPE=auto` 会在 Artisan environment 与 `APP_ENV` 不同时，使用
`--project-a` / `--project-b` 后缀隔离 runtime、slow SQL、cursor、partial ack 和同步锁。
普通 `.env` 单环境运行继续使用原路径，不发生目录变化。也可以显式指定稳定 scope；设为
`false`、`off` 或 `none` 可关闭自动隔离：

```env
MOO_MONITOR_STORAGE_SCOPE=project-a
```

发布配置文件可查看更多选项：

```bash
php artisan vendor:publish --tag=moo-monitor-config
```

配置文件位置：

```text
config/moo-monitor.php
```

## 验证是否接入成功

清理配置缓存：

```bash
php artisan config:clear
```

**推荐：一键自检。** 不用等真异常发生，直接推一条 runtime + 一条慢 SQL 到云端，逐步确认配置是否有效：

```bash
php artisan moo:cloud:test
```

输出会逐项反馈：配置检查 → 心跳（连通 + 鉴权 + SDK/宿主元信息）→ 推送自检 runtime → 推送自检慢 SQL。全绿即说明「采集 → 推送 → 云端」整条管道通畅。自检记录是可识别的（runtime 类名 `SelfTestException`、SQL 带 `self-test` 标记），默认保留为「未处理」，这样你能去云端 runtimes / slow_queries 列表**亲眼确认数据已到达**；确认后在 UI 解决即可，或加 `--resolve` 让命令推送后自动标记已解决。重复运行只 upsert 同一条，不会堆积。

也可以用既有方式手动验证 —— 查看本地是否有待推送数据：

```bash
php artisan moo:cloud:push --dry-run
```

真实推送一次：

```bash
php artisan moo:cloud:push
```

如果云端没有数据，按顺序检查：

1. `.env` 中 `MOO_MONITOR_CLOUD_ENABLED` 是否为 `true`。
2. `MOO_MONITOR_CLOUD_TOKEN` 是否正确，且 token 有对应能力。
3. 项目是否真的触发过异常或慢 SQL。
4. 慢 SQL 是否已开启，阈值是否过高。
5. 服务器是否能访问 `MOO_MONITOR_CLOUD_URL`。
6. 内网自签证书导致 TLS 校验失败时，设 `MOO_MONITOR_CLOUD_VERIFY=false`。

### 推送一直失败

云端会为批次中的每条记录返回处理结果。本地收到 `saved` 或 `filtered` 后立即确认该记录，后续不会重复上报；临时失败只重试对应记录，不会拖累同批其他记录。已经确认的 `resolved` 快照会在内容未发生变化时立即单文件回收。

`moo:cloud:push` 失败时会打印待重试记录的 hash（自动调度的后台运行则写入日志），据此定位：

> `moo:cloud:*` 是监控链路自身命令，其调度非零退出不会写回 runtime 缓冲，避免 Cloud 故障形成自反馈；推送中断由 Cloud heartbeat 哨兵检测。

```text
storage/moo-monitor/<runtimes|sql-slows>/<open|resolved>/<hash>.yaml
```

云端明确判定为不可重试的记录会自动移入对应类型目录下的 `cloud-rejected/` 留证，例如 `storage/moo-monitor/runtimes/cloud-rejected/`、`storage/moo-monitor/sql-slows/cloud-rejected/`，并不再阻塞游标。该目录中的 YAML 可用于排查字段或契约问题；确认无需保留后再手动清理即可。

## 自动推送

当以下配置同时满足时，包会自动注册每分钟推送任务：

```env
MOO_MONITOR_CLOUD_ENABLED=true
MOO_MONITOR_CLOUD_SCHEDULE=true
```

宿主项目仍然需要正常运行 Laravel scheduler。服务器 crontab 示例：

```cron
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

多 `.env.XXX` 部署请让每个 scheduler 进程显式携带对应 `--env`。这类 scoped push 会前台执行，
避免 Laravel 的后台 `schedule:finish` 子进程因缺少环境选择器而无法释放环境专属互斥锁。

没有接入 scheduler 时，也可以用自己的定时任务执行：

```bash
php artisan moo:cloud:push
```

## 常用命令

| 命令 | 说明 |
| --- | --- |
| `php artisan moo:cloud:test` | 自检：推一条 runtime + 一条慢 SQL 到云端，确认接入配置有效。 |
| `php artisan moo:cloud:push` | 推送 runtime 异常和慢 SQL 到云端。 |
| `php artisan moo:cloud:push --dry-run` | 只统计待推送数量，不发送请求。 |
| `php artisan moo:cloud:push --type=runtimes` | 只推送运行时异常。 |
| `php artisan moo:cloud:push --type=slow_sql` | 只推送慢 SQL。 |
| `php artisan moo:cloud:push --all` | 忽略游标，全量重推。云端按 hash 幂等处理。 |
| `php artisan moo:cloud:mcp` | 启动 MCP server，供 AI 工具读取云端错误和待办。 |
| `php artisan moo:monitor:migrate` | 从 `moo-scaffold <= 3.8` 迁移旧监控数据。 |

## 常用配置

| 配置 | 默认值 | 说明 |
| --- | --- | --- |
| `MOO_MONITOR_STORAGE_SCOPE` | `auto` | 多 `.env.XXX` 项目的本地状态隔离；可设显式 scope，或设 `false` / `off` / `none` 关闭。 |
| `MOO_MONITOR_RUNTIME_ENABLED` | `true` | 是否采集运行时异常。 |
| `MOO_MONITOR_EXCEPTION_LOG_CONTEXT_HOOK` | `true` | 是否捕获错误日志 context 里的 `exception` 对象。 |
| `MOO_MONITOR_EXCEPTION_LOG_MESSAGE_HOOK` | `true` | 是否捕获 `Log::error($e)` 这类「异常被字符串化、只写日志」的形态。 |
| `MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK` | `true` | 是否补采 `abort(500/502/503)` 等 HttpException 5xx（只读观察、放行默认渲染，不改宿主响应）。 |
| `MOO_MONITOR_EXCEPTION_SCHEDULE_EXIT_HOOK` | `true` | 是否捕获 exec 型调度任务的非零退出码。 |
| `MOO_MONITOR_EXCEPTION_QUEUE_FAILED_HOOK` | `true` | 是否捕获 Laravel 队列失败事件。 |
| `MOO_MONITOR_RUNTIME_MAX_OPEN` | `500` | 本地 open 异常最大数量。 |
| `MOO_MONITOR_RUNTIME_DAILY_CAP` | `10` | 同一异常每天最多写盘次数。 |
| `MOO_MONITOR_SQL_SLOW_ENABLED` | `false` | 是否采集慢 SQL。 |
| `MOO_MONITOR_SQL_SLOW_THRESHOLD_MS` | `100` | 慢 SQL 阈值，单位毫秒。 |
| `MOO_MONITOR_SQL_SLOW_MAX_OPEN` | `500` | 本地 open 慢 SQL 最大数量。 |
| `MOO_MONITOR_CLOUD_ENABLED` | `false` | 是否启用云端推送。 |
| `MOO_MONITOR_CLOUD_URL` | `https://c.mooeen.com` | 云端地址。 |
| `MOO_MONITOR_CLOUD_TOKEN` | 空 | 项目接入 token。 |
| `MOO_MONITOR_CLOUD_TIMEOUT` | `5` | 推送 HTTP 超时（秒）。 |
| `MOO_MONITOR_CLOUD_VERIFY` | `true` | TLS 证书校验，内网自签证书可设为 `false`。 |
| `MOO_MONITOR_CLOUD_BATCH` | `100` | 每批推送数量。 |
| `MOO_MONITOR_CLOUD_SCHEDULE` | `true` | 与 `ENABLED` 同时为真时自动挂每分钟推送。 |
| `MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS` | `7` | 推送成功后本地缓冲保留天数。 |

另有三项数组型配置按宿主布局微调（无 env，直接改 `config/moo-monitor.php`）：`runtime.auth_guards`（采集登录用户时依次尝试的 guard，默认 `admin` / `user` / `web`；用 `api` / `sanctum` / 自定义 guard 的宿主在此追加）、`runtime.app_frame_prefixes`（调用栈「宿主业务帧」的路径前缀，默认 `app/` / `routes/`；`Modules/` / `src/` 等布局自行追加）、`exception.log_context_levels`（触发日志钩子的级别白名单，默认 `error` 及以上）。

更多配置见 `config/moo-monitor.php`，每个字段都有注释。

## 本地数据目录

数据默认写在宿主项目的 `storage/moo-monitor/`：

```text
storage/moo-monitor/
├── runtimes/
│   ├── open/
│   ├── resolved/
│   ├── deleted/
│   └── cloud-rejected/
├── sql-slows/
│   ├── open/
│   ├── resolved/
│   ├── deleted/
│   └── cloud-rejected/
├── cloud-sync.json
└── cloud-sync.json.acks
```

`cloud-sync.json.acks` 保存已逐条确认但尚未被全局游标覆盖的版本；同步完成后会自动收敛。该目录会自动写入 `.gitignore`，监控数据不会进入宿主项目 git。

多环境 auto scope 启用时，目录和文件会变为同一后缀，例如：

```text
storage/moo-monitor/
├── runtimes--project-a/
├── sql-slows--project-a/
├── cloud-sync--project-a.json
└── cloud-sync--project-a.json.acks
```

新 scope 没有历史游标，首次推送会幂等发送该 scope 当前缓冲中的全部记录；云端按
`(project, hash)` upsert，不会在同一项目生成重复条目。旧的未加 scope 目录不会被自动搬入任一项目，
避免无法确认归属的历史混合数据被再次误投。

## MCP 接入

MCP 用于让 AI 工具直接读取云端 runtime 错误和待办，适合“拉问题 → 看上下文 → 修复 → 回写已解决”的流程。

示例：

```bash
claude mcp add moo-cloud -- php artisan moo:cloud:mcp
```

MCP 复用 `.env` 中的 `MOO_MONITOR_CLOUD_URL` 和 `MOO_MONITOR_CLOUD_TOKEN`，不需要额外 token，但该 Token 必须包含 `mcp` 能力。只有 `runtimes` / `slow_queries` 上报能力的 Token 无权读取或处置云端问题。

与 `--env` 隔离的 push 不同，`moo:cloud:mcp` 服务器本身不按 `--env` 切换项目：它只绑定所加载 `.env` 中那一个 `MOO_MONITOR_CLOUD_TOKEN`，即单个 Cloud 项目。因此多 `.env.XXX` 部署要按项目分别注册，各自带上对应 `--env`：

```bash
claude mcp add moo-cloud-a -- php artisan moo:cloud:mcp --env=PROJECT_A
claude mcp add moo-cloud-b -- php artisan moo:cloud:mcp --env=PROJECT_B
```

`list_open_runtimes` 和 `list_open_todos` 每次最多返回 50 条。响应提示 `has_more=true` 时，下一次调用保持相同的 `status` 与 `limit`，并把提示中的 `next_offset` 作为 `offset` 传入；直到提示“已到末页”。若后续页调用遇到旧 Cloud 未返回分页元数据，工具会直接报错，避免重复读取第一页。

待办分四类：`bug`（待分类缺陷）、`frontend_bug`（前端缺陷）、`backend_bug`（后端缺陷）和 `task`（普通任务）。`list_open_todos` / `get_todo` 返回的「类型」字段会标明，便于 AI 选择正确代码范围并区分「修缺陷」和「做任务」。

## 从 moo-scaffold <= 3.8 迁移

`moo-scaffold <= 3.8` 中的监控能力已经拆分到本包。升级时按下面步骤处理：

```bash
composer update charsen/moo-scaffold
php artisan moo:monitor:migrate
php artisan moo:cloud:push --dry-run
```

旧环境变量需要改名前缀：

| 旧变量 | 新变量 |
| --- | --- |
| `SCAFFOLD_RUNTIME_*` | `MOO_MONITOR_RUNTIME_*` |
| `SCAFFOLD_SQL_SLOW_*` | `MOO_MONITOR_SQL_SLOW_*` |
| `SCAFFOLD_CLOUD_*` | `MOO_MONITOR_CLOUD_*` |

不要在 `moo-scaffold <= 3.8` 的项目上单独安装本包，否则旧采集器和新采集器可能重复记录。建议先升级到 `moo-scaffold >= 3.9`。

## 开发

```bash
composer install
composer test            # Pest 套件（testbench ^10 = Laravel 12）
composer lint            # pint 风格检查（composer format 自动修）
composer quality         # check:composer + lint + test 一把过
```

### 发版前低版本冒烟

Pest 套件只在 Laravel 12 上跑，**版本相关的 API 兼容问题可能测试全绿却在老宿主上 fatal**
（如 `Http::retry(..., throw:)` 命名参数 L9 才有、L8 会崩）。放宽框架约束或动到框架 API 时，
发版前对低版本宿主跑一次接入冒烟：

```bash
composer smoke:lower            # 默认 Laravel 8（最低支持）
composer smoke:lower -- '^9.0'  # 或指定 9 / 10 / 11
```

它会临时建一个目标版本的 Laravel app、用 path 仓库装本包，断言 provider 能 boot、命令注册、
`moo:cloud:test` 打不可达云端时不 PHP fatal（即真正执行了 retry()/Http 路径），跑完自动清理。

## License

MIT
