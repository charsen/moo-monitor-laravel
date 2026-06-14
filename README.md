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
| 运行时异常监控 | 自动接入 Laravel 异常上报链路，记录异常类型、消息、文件行号、请求 URL、用户、调用栈和源码片段。 |
| 慢 SQL 监控 | 监听 Laravel `QueryExecuted` 事件，超过阈值的 SQL 会被记录，包含执行耗时、SQL 内容、触发位置和请求信息。 |
| 相同问题聚合 | 相同异常或相同慢 SQL 会按 hash 聚合，累计出现次数，避免同一个问题刷屏。 |
| 敏感信息脱敏 | 对 password、token、secret、authorization 等常见敏感字段做脱敏处理。 |
| 本地缓冲 | 数据先写入 `storage/moo-monitor/`，云端或网络异常不会影响业务请求。 |
| 云端推送 | 通过 `moo:cloud:push` 增量推送到 moo-scaffold-cloud，云端负责查看、告警和处置。 |
| AI / MCP 辅助处理 | 可通过 `moo:cloud:mcp` 让 Claude Code / Codex 等工具读取云端 runtime 错误和待办，并回写处理状态。 |
| 旧版迁移 | 支持从 `moo-scaffold <= 3.8` 的本地监控目录迁移到新目录。 |

## 环境要求

- PHP 8.2+
- Laravel 12
- 依赖：`laravel/framework`、`symfony/yaml`

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

# 私有部署时再配置，默认是 https://sc.mooeen.com
# MOO_MONITOR_CLOUD_URL=https://sc.mooeen.com
```

Token 在 moo-scaffold-cloud 的「接入 Token」页面生成，建议开启 `runtimes` 和 `slow_queries` 能力。

运行时异常默认开启：

```env
MOO_MONITOR_RUNTIME_ENABLED=true
```

慢 SQL 默认关闭，需要时开启：

```env
MOO_MONITOR_SQL_SLOW_ENABLED=true
MOO_MONITOR_SQL_SLOW_THRESHOLD_MS=100
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

**推荐:一键自检。** 不用等真异常发生,直接推一条 runtime + 一条慢 SQL 到云端,逐步确认配置是否有效：

```bash
php artisan moo:cloud:test
```

输出会逐项反馈:配置检查 → 心跳(连通 + 鉴权)→ 推送自检 runtime → 推送自检慢 SQL。全绿即说明「采集 → 推送 → 云端」整条管道通畅。自检记录是可识别的(runtime 类名 `SelfTestException`、SQL 带 `self-test` 标记),默认保留为「未处理」,这样你能去云端 runtimes / slow_queries 列表**亲眼确认数据已到达**;确认后在 UI 解决即可,或加 `--resolve` 让命令推送后自动标记已解决。重复运行只 upsert 同一条,不会堆积。

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

为保证不丢数据，某一类（runtimes / slow_sql）只要有一批推送失败，游标就不前进、下次重试同一批，该类的本地缓冲也不会被回收。因此**单条被云端持久拒收的记录会卡住整类推送并让本地缓冲持续增长**。

`moo:cloud:push` 失败时会打印卡住的记录 hash（自动调度的后台运行则写入日志），据此定位：

```text
storage/moo-monitor/<runtimes|sql-slows>/<open|resolved>/<hash>.yaml
```

确认该记录无需保留后，可手动移走或删除对应 yaml，再重新推送。

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

没有接入 scheduler 时，也可以用自己的定时任务执行：

```bash
php artisan moo:cloud:push
```

## 常用命令

| 命令 | 说明 |
| --- | --- |
| `php artisan moo:cloud:test` | 自检:推一条 runtime + 一条慢 SQL 到云端,确认接入配置有效。 |
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
| `MOO_MONITOR_RUNTIME_ENABLED` | `true` | 是否采集运行时异常。 |
| `MOO_MONITOR_RUNTIME_MAX_OPEN` | `500` | 本地 open 异常最大数量。 |
| `MOO_MONITOR_RUNTIME_DAILY_CAP` | `10` | 同一异常每天最多写盘次数。 |
| `MOO_MONITOR_SQL_SLOW_ENABLED` | `false` | 是否采集慢 SQL。 |
| `MOO_MONITOR_SQL_SLOW_THRESHOLD_MS` | `100` | 慢 SQL 阈值，单位毫秒。 |
| `MOO_MONITOR_SQL_SLOW_MAX_OPEN` | `500` | 本地 open 慢 SQL 最大数量。 |
| `MOO_MONITOR_CLOUD_ENABLED` | `false` | 是否启用云端推送。 |
| `MOO_MONITOR_CLOUD_URL` | `https://sc.mooeen.com` | 云端地址。 |
| `MOO_MONITOR_CLOUD_TOKEN` | 空 | 项目接入 token。 |
| `MOO_MONITOR_CLOUD_TIMEOUT` | `5` | 推送 HTTP 超时(秒)。 |
| `MOO_MONITOR_CLOUD_VERIFY` | `true` | TLS 证书校验,内网自签证书可设为 `false`。 |
| `MOO_MONITOR_CLOUD_BATCH` | `100` | 每批推送数量。 |
| `MOO_MONITOR_CLOUD_SCHEDULE` | `true` | 与 `ENABLED` 同时为真时自动挂每分钟推送。 |
| `MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS` | `7` | 推送成功后本地缓冲保留天数。 |

更多配置见 `config/moo-monitor.php`，每个字段都有注释。

## 本地数据目录

数据默认写在宿主项目的 `storage/moo-monitor/`：

```text
storage/moo-monitor/
├── runtimes/
│   ├── open/
│   ├── resolved/
│   └── deleted/
├── sql-slows/
│   ├── open/
│   ├── resolved/
│   └── deleted/
└── cloud-sync.json
```

该目录会自动写入 `.gitignore`，监控数据不会进入宿主项目 git。

## MCP 接入

MCP 用于让 AI 工具直接读取云端 runtime 错误和待办，适合“拉问题 → 看上下文 → 修复 → 回写已解决”的流程。

示例：

```bash
claude mcp add moo-cloud -- php artisan moo:cloud:mcp
```

MCP 复用 `.env` 中的 `MOO_MONITOR_CLOUD_URL` 和 `MOO_MONITOR_CLOUD_TOKEN`，不需要额外 token。

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
composer test
./vendor/bin/pint
```

## License

MIT
