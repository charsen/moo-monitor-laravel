# moo-monitor-laravel

> Laravel 运行时异常 + 慢 SQL 监控 SDK,推送到 [moo-scaffold-cloud](https://gitee.com/charsen/moo-scaffold-cloud) 集中查看、告警与处置。
>
> 对标 [moo-monitor-vue](https://gitee.com/charsen/moo-monitor-vue)(Vue 3 前端监控):**前端装 monitor-vue,Laravel 后端装本包**,云端统一汇聚。原内置于 [moo-scaffold](https://gitee.com/charsen/moo-scaffold)(≤3.8),3.9.0 起抽离成独立包 —— 不用 scaffold 的 Laravel 项目也能接入云端。

定位:**headless 采集端**。本地不带任何查看界面 —— 采集 → 缓冲(`storage/moo-monitor/`,自带 .gitignore)→ 推送,查看与处置统一在云端。

环境:Laravel 12 · PHP 8.2+。零额外依赖(仅 framework + symfony/yaml)。

## 能力一览

| 能力 | 机制 | 说明 |
|---|---|---|
| 运行时异常采集 | 自动挂 reportable 钩子(宿主零接入) | trace + 触发源码片段 ±10 行 + 请求现场;敏感字段/JWT/Bearer 脱敏;同 hash 聚合 count、日上限防刷 |
| 慢 SQL 采集 | 监听 `QueryExecuted`(同步,PDO 不可入队列) | 超阈值落盘,normalized SQL + file:line 聚合;binding 还原 + 值侧脱敏;skip_patterns 降噪 |
| 云端推送 | `moo:cloud:push`(命令 / scheduler 自动挂) | 增量(meta.updated_at 游标)、幂等(云端按 project+hash upsert)、推后回收本地;每拍带心跳 |
| AI 接入(MCP) | `moo:cloud:mcp`(stdio JSON-RPC) | 云端 runtime 错误 + 待办六工具:拉取 → 认领 → 修复 → 回写闭环 |
| 旧版迁移 | `moo:monitor:migrate` | scaffold ≤3.8 的本地 yaml / 游标平移到新布局 + .env 改名体检(幂等) |

## 安装

```bash
composer require charsen/moo-monitor-laravel
```

> 私包未发 Packagist,宿主 composer.json 先加仓库源:
> ```json
> "repositories": [{ "type": "vcs", "url": "git@gitee.com:charsen/moo-monitor-laravel.git" }]
> ```
> 本地联调改用 path repo:`{ "type": "path", "url": "../moo-monitor-laravel" }`。
> 装了 moo-scaffold ≥3.9 的项目**无需单独装**,依赖自动带入。

`.env` 两行接入(token 在云端「接入 Token」页生成,勾 `runtimes` + `slow_queries`):

```env
MOO_MONITOR_CLOUD_ENABLED=true
MOO_MONITOR_CLOUD_TOKEN=moo_xxxxxxxx
# MOO_MONITOR_CLOUD_URL=https://sc.mooeen.com   # 默认值,私有部署才覆盖
```

完事。异常采集默认开启(`MOO_MONITOR_RUNTIME_ENABLED=true`);慢 SQL 默认关,要开:

```env
MOO_MONITOR_SQL_SLOW_ENABLED=true
MOO_MONITOR_SQL_SLOW_THRESHOLD_MS=100
```

自动推送依赖宿主在跑 Laravel scheduler(`schedule:run`);没跑就手动 `php artisan moo:cloud:push`。

完整配置:`php artisan vendor:publish --tag=moo-monitor-config` → `config/moo-monitor.php`(max_open / daily_cap / mask_keys / skip_patterns / 回收阈值等,均有注释)。

## 命令

| 命令 | 作用 |
|---|---|
| `moo:cloud:push [--type=runtimes\|slow_sql\|both] [--all] [--dry-run]` | 增量、幂等推送本地缓冲到云端,推后回收 + 心跳 |
| `moo:cloud:mcp` | MCP server:云端 runtime 错误 + 待办暴露给本仓 AI(Claude Code / Codex) |
| `moo:monitor:migrate [--dry-run]` | 从 moo-scaffold ≤3.8 迁移旧数据 / 游标(幂等);.env 旧变量体检 |

MCP 接入(零额外 token,复用 `.env` 的 URL + TOKEN):

```bash
claude mcp add moo-cloud -- php artisan moo:cloud:mcp
```

## 从 moo-scaffold ≤3.8 迁移

env 改名对照(**无兼容回落**,前缀 `SCAFFOLD_` → `MOO_MONITOR_`,后缀不变):

| 旧 | 新 |
|---|---|
| `SCAFFOLD_RUNTIME_*` | `MOO_MONITOR_RUNTIME_*` |
| `SCAFFOLD_SQL_SLOW_*` | `MOO_MONITOR_SQL_SLOW_*` |
| `SCAFFOLD_CLOUD_*` | `MOO_MONITOR_CLOUD_*` |

```bash
composer update charsen/moo-scaffold      # 3.9.0 自动带入本包
# .env 按上表改名
php artisan moo:monitor:migrate           # 平移 scaffold/{runtimes,sql-slows} 与游标(幂等)
php artisan moo:cloud:push --dry-run      # 验证管道
# bootstrap/app.php 里旧的 ExceptionDispatcher reportable 手动接入可删(留着也不会双计)
```

hash 算法与云端契约不变,升级后云端记录无缝延续。

> ⚠ **双重捕获提醒**:不要在 scaffold ≤3.8 的宿主上单独装本包(新旧两套采集并存会重复记录);升级 scaffold 到 3.9.0 一步到位。

## 本地数据布局

```
storage/moo-monitor/
├── .gitignore            # 自动写入(* + !.gitignore),数据不入宿主 git
├── runtimes/{open,resolved,deleted}/<hash>.yaml
├── sql-slows/{open,resolved,deleted}/<hash>.yaml
└── cloud-sync.json       # 推送游标
```

本地仅是云端推送前的**临时缓冲**:推送成功后 `resolved` 全清、`open` 留作聚合锚点(仅清 `local_retention_days` 天前的;`=0` 完全不回收)。

## 开发

```bash
composer install
composer test            # Pest(Orchestra Testbench,零 env 全绿)
./vendor/bin/pint        # 代码风格
```

## License

MIT
