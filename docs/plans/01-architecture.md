# Plan 01：从 moo-scaffold 抽离监控能力，建立 moo-monitor-laravel

> 状态：已实施（2026-06-12 完成；§9 的 ⑥ 真实宿主验证、⑦ 发版打 tag 待做）
> 日期：2026-06-12
> 关联：moo-scaffold 3.8.x → 3.9.0、moo-scaffold-cloud（云端零改动）、moo-monitor-vue（对标参照）

## 1. 背景与目标

moo-scaffold 当前内置了「运行时异常监测 + 慢 SQL 捕获 + 云端推送 + MCP」整条链路。
这条链路与代码生成器的生命周期完全不同（监控在生产期常驻，生成器只在开发期跑），
且导致不用 scaffold 工作流的 Laravel 项目无法接入 moo-scaffold-cloud。

**目标**：

1. 抽出独立包 `charsen/moo-monitor-laravel`（命名空间 `Mooeen\Monitor`），
   任何 Laravel 项目 composer require 即可接入 moo-scaffold-cloud
   —— 对标 moo-monitor-vue 之于 Vue 3。
2. moo-scaffold 升级 **3.9.0**,`composer require charsen/moo-monitor-laravel`,
   删除自带的监控代码，回归「代码生成 + 开发后台」。
3. 云端 intake 契约不变，moo-scaffold-cloud **零改动**。

**原则：搬家不装修。** 本次只做平移 + 必要解耦（改命名空间、配置名、存储路径），
不新增采集能力（队列失败、计划任务失败等留给后续版本）。

## 2. 包边界

### 2.1 迁入 moo-monitor-laravel（18 个文件）

| 来源（moo-scaffold） | 去向（Mooeen\Monitor） |
|---|---|
| `Support/RuntimeErrorRecorder.php` | `Recorder/RuntimeErrorRecorder.php` |
| `Support/SqlSlowRecorder.php` | `Recorder/SqlSlowRecorder.php` |
| `Support/SqlSlowListener.php` | `Recorder/SqlSlowListener.php` |
| `Support/Concerns/ManagesBucketedRecords.php` | `Recorder/Concerns/ManagesBucketedRecords.php` |
| `Support/Concerns/WritesBucketedYaml.php` | `Recorder/Concerns/WritesBucketedYaml.php` |
| `Support/Concerns/TracksDailyCap.php` | `Recorder/Concerns/TracksDailyCap.php` |
| `Support/Concerns/MasksSensitiveUrl.php` | `Recorder/Concerns/MasksSensitiveUrl.php` |
| `Support/ExceptionDispatcher.php` | `ExceptionDispatcher.php` |
| `Support/CloudClient.php` | `Cloud/CloudClient.php` |
| `Support/CloudSync.php` | `Cloud/CloudSync.php` |
| `Command/CloudPushCommand.php` | `Command/CloudPushCommand.php`（命令名不变 `moo:cloud:push`） |
| `Command/CloudMcpCommand.php` | `Command/CloudMcpCommand.php`（命令名不变 `moo:cloud:mcp`） |
| 相关测试 ~7 个文件 | `tests/Feature/...`（改 namespace） |

**命令名保持 `moo:cloud:*` 不变**：scaffold 3.9 依赖本包后，宿主的
`claude mcp add moo-cloud -- php artisan moo:cloud:mcp` 配置、crontab、文档全部无感。

### 2.2 留在 moo-scaffold（改为调用本包）

UI 层全部留下 —— 本包是 **headless** 的（对标 moo-monitor-vue:SDK 不带界面）：

- `CloudController` + `cloud/index.blade.php`（/scaffold/cloud 控制台）
- `CloudRedirectController`（/scaffold/runtimes、/scaffold/sql-slows 302 跳云端）
- `ScaffoldController::getCloudSummary()`（首页云端汇聚面板）
- 以上改为 `use Mooeen\Monitor\Cloud\CloudClient` 等，逻辑不变。

理由：这些页面依赖 scaffold 的认证中间件、Blade 组件、CSP 体系。
独立宿主（不装 scaffold）不需要本地 UI —— 云端就是唯一查看入口。

### 2.3 不迁（直接删除）

- `Command/CloudAdoptCommand.php`(`moo:cloud:adopt`):git-sync 时代的迁移工具，
  新存储布局在 storage/ 下天然不入 git，该命令使命完成。
  本包提供新的 `moo:monitor:migrate` 替代（见 §8）。

## 3. 包骨架

```
moo-monitor-laravel/
├── src/
│   ├── MonitorProvider.php           # 唯一 Provider(auto-discovery)
│   ├── ExceptionDispatcher.php       # 异常分发入口
│   ├── Recorder/
│   │   ├── RuntimeErrorRecorder.php
│   │   ├── SqlSlowRecorder.php
│   │   ├── SqlSlowListener.php
│   │   └── Concerns/                 # 4 个 trait
│   ├── Cloud/
│   │   ├── CloudClient.php           # 纯传输层(intake/summary/heartbeat/MCP 读修)
│   │   └── CloudSync.php             # 增量推送编排 + 游标 + 本地回收
│   └── Command/
│       ├── CloudPushCommand.php      # moo:cloud:push
│       ├── CloudMcpCommand.php       # moo:cloud:mcp
│       └── MigrateCommand.php        # moo:monitor:migrate(新增,见 §8)
├── config/
│   └── moo-monitor.php
├── tests/
│   ├── Feature/                      # Pest 3 + Orchestra Testbench 10(沿用 scaffold 体系)
│   └── TestCase.php
├── composer.json
├── phpunit.xml
├── pint.json
├── README.md
├── CHANGELOG.md
└── docs/
    └── plans/01-architecture.md      # 本文档
```

### composer.json 要点

```json
{
    "name": "charsen/moo-monitor-laravel",
    "description": "Laravel runtime error & slow SQL monitoring SDK for moo-scaffold-cloud",
    "require": {
        "php": "^8.2",
        "laravel/framework": "^12.0",
        "symfony/yaml": "^7.0"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0",
        "pestphp/pest": "^3.0",
        "laravel/pint": "^1.13"
    },
    "autoload": { "psr-4": { "Mooeen\\Monitor\\": "src/" } },
    "extra": {
        "laravel": { "providers": ["Mooeen\\Monitor\\MonitorProvider"] }
    }
}
```

- 依赖刻意压到最低：framework + symfony/yaml（YAML 落盘格式不变）。
- 版本从 **0.1.0** 起；scaffold 3.9.0 require `^0.1`；稳定后发 1.0。
- 双模式安装与 scaffold 相同：本地 path repo(`../moo-monitor-laravel`)，生产 vcs gitee。

## 4. 配置设计（全新命名）

文件：`config/moo-monitor.php`(`vendor:publish --tag=moo-monitor-config`)。
**不读任何 SCAFFOLD_* 旧变量**，干净切换（决策：2026-06-12）。

```php
return [
    // 运行时异常采集
    'runtime' => [
        'enabled'         => env('MOO_MONITOR_RUNTIME_ENABLED', true),
        'path'            => env('MOO_MONITOR_RUNTIME_PATH', 'moo-monitor/runtimes'), // 相对 storage_path()
        'max_open'        => (int) env('MOO_MONITOR_RUNTIME_MAX_OPEN', 500),
        'daily_cap'       => (int) env('MOO_MONITOR_RUNTIME_DAILY_CAP', 10),
        'mask_keys'       => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],
        'string_truncate' => 200,
        'trace_max_bytes' => 65536,
        'snippet_lines'   => 10,
        'cache_ttl'       => (int) env('MOO_MONITOR_RUNTIME_CACHE_TTL', 30),
    ],

    // 异常分发行为
    'exception' => [
        'auto_hook'           => true,   // Provider 自动挂 reportable(新增,见 §5)
        'cli_experiment_skip' => true,
        'console_input_skip'  => true,
    ],

    // 慢 SQL 采集
    'sql_slow' => [
        'enabled'       => (bool) env('MOO_MONITOR_SQL_SLOW_ENABLED', false),
        'threshold_ms'  => (int) env('MOO_MONITOR_SQL_SLOW_THRESHOLD_MS', 100),
        'path'          => env('MOO_MONITOR_SQL_SLOW_PATH', 'moo-monitor/sql-slows'), // 相对 storage_path()
        'mask_keys'     => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],
        'max_open'      => (int) env('MOO_MONITOR_SQL_SLOW_MAX_OPEN', 500),
        'daily_cap'     => (int) env('MOO_MONITOR_SQL_SLOW_DAILY_CAP', 10),
        'skip_patterns' => [],
        'cache_ttl'     => (int) env('MOO_MONITOR_SQL_SLOW_CACHE_TTL', 30),
    ],

    // 云端(moo-scaffold-cloud)
    'cloud' => [
        'enabled'              => (bool) env('MOO_MONITOR_CLOUD_ENABLED', false),
        'base_url'             => rtrim((string) env('MOO_MONITOR_CLOUD_URL', 'https://sc.mooeen.com'), '/'),
        'token'                => (string) env('MOO_MONITOR_CLOUD_TOKEN', ''),
        'timeout'              => (int) env('MOO_MONITOR_CLOUD_TIMEOUT', 5),
        'batch'                => (int) env('MOO_MONITOR_CLOUD_BATCH', 100),
        'verify'               => (bool) env('MOO_MONITOR_CLOUD_VERIFY', true),
        'push' => [
            'runtimes' => (bool) env('MOO_MONITOR_CLOUD_PUSH_RUNTIMES', true),
            'slow_sql' => (bool) env('MOO_MONITOR_CLOUD_PUSH_SLOW_SQL', true),
        ],
        'schedule'             => (bool) env('MOO_MONITOR_CLOUD_SCHEDULE', true),
        'local_retention_days' => (int) env('MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS', 7),
    ],
];
```

### env 改名对照表（写入 README 迁移章节）

| 旧（scaffold ≤3.8） | 新（moo-monitor-laravel） |
|---|---|
| `SCAFFOLD_RUNTIME_*` | `MOO_MONITOR_RUNTIME_*` |
| `SCAFFOLD_SQL_SLOW_*` | `MOO_MONITOR_SQL_SLOW_*` |
| `SCAFFOLD_CLOUD_URL` | `MOO_MONITOR_CLOUD_URL` |
| `SCAFFOLD_CLOUD_TOKEN` | `MOO_MONITOR_CLOUD_TOKEN` |
| `SCAFFOLD_CLOUD_*`（其余） | `MOO_MONITOR_CLOUD_*` |

友好提醒（不做兼容）：`moo:cloud:push` 启动时若检测到
`SCAFFOLD_CLOUD_TOKEN` 已设而 `MOO_MONITOR_CLOUD_TOKEN` 为空，输出一条
warning 提示改名 —— 只提示，不回落。

## 5. Provider 注册流程（MonitorProvider）

```
register():
  - mergeConfigFrom(config/moo-monitor.php)
  - singleton: RuntimeErrorRecorder / SqlSlowRecorder / SqlSlowListener / ExceptionDispatcher
  - runningInConsole: commands([CloudPushCommand, CloudMcpCommand, MigrateCommand])

boot():
  - publishes(config)
  - Event::listen(QueryExecuted::class, [SqlSlowListener::class, 'handle'])   # 同 scaffold,必须同步
  - 异常自动挂钩(exception.auto_hook=true 时):
      callAfterResolving(ExceptionHandler::class, fn ($h) =>
          $h->reportable(fn (Throwable $e) => app(ExceptionDispatcher::class)->dispatch($e)))
  - scheduler 自动挂载(runningInConsole + cloud.enabled + cloud.schedule):
      moo:cloud:push everyMinute, withoutOverlapping(10), runInBackground
```

**变化点：异常钩子从「宿主手动接」改为「包自动挂」**(scaffold ≤3.8 要求宿主在
bootstrap/app.php 里手动 `$exceptions->reportable(...)`)。
配套防双计：`ExceptionDispatcher::dispatch()` 内用 `spl_object_id` 记录本请求已
处理的异常对象，同一异常对象第二次进来直接跳过 —— 即使宿主保留了旧的手动接入，
也不会重复计数。`exception.auto_hook=false` 可退回纯手动模式。

接入体验对齐 monitor-vue 的「一行接入」：

```bash
composer require charsen/moo-monitor-laravel
# .env 配 MOO_MONITOR_CLOUD_ENABLED=true + MOO_MONITOR_CLOUD_TOKEN=xxx 即完成
```

## 6. 本地存储布局（决策：storage/moo-monitor/）

```
storage/moo-monitor/
├── .gitignore            # 包在 ensureDir 时自动写入("*" + "!.gitignore"),自我屏蔽
├── runtimes/
│   ├── open/{hash}.yaml
│   ├── resolved/{hash}.yaml
│   └── deleted/{hash}.yaml
├── sql-slows/
│   ├── open/{hash}.yaml
│   ├── resolved/{hash}.yaml
│   └── deleted/{hash}.yaml
└── cloud-sync.json       # 推送游标(原 storage/app/scaffold/cloud-sync.json)
```

- 路径解析从 `base_path()` 相对改为 **`storage_path()` 相对**（配置可覆盖，绝对路径也支持）。
- Laravel 默认 .gitignore 不覆盖 storage/ 下新顶层目录，故包**首次建目录时自动落一个
  自我屏蔽的 .gitignore** —— 与 git 彻底解耦，无须宿主动手。
- YAML 内部字段结构、hash 算法、三桶（open/resolved/deleted）语义**完全不变**,
  云端 intake 契约因此不变。

## 7. moo-scaffold 3.9.0 改动清单

1. **composer.json**：`require` 增 `charsen/moo-monitor-laravel: ^0.1`；版本号 3.9.0。
2. **删除文件**（18 个，见 §2.1 来源列）+ 对应测试迁走。
3. **ScaffoldProvider 瘦身**：删除 SqlSlow 事件监听、Runtime/SqlSlow/Dispatcher
   singleton、scheduler 挂载、3 个 Cloud 命令注册 —— 全部由 MonitorProvider 接管。
4. **config/config.php**：删除 `runtime` / `exception` / `sql_slow` / `cloud` 四段。
5. **UI 调用点改 import**：
   - `ScaffoldController::getCloudSummary()` → `Mooeen\Monitor\Cloud\CloudClient`
   - `CloudController` → Monitor 的 CloudSync / 两个 Recorder / CloudClient
   - `CloudRedirectController` → `config('moo-monitor.cloud.base_url')`
   - 顺手清理：`DesignerController` / `RouteController` 中无用的
     RuntimeErrorRecorder 注入（本来就没用到）。
6. **配置 UI(/scaffold/config)**：原来编辑 `scaffold.cloud` 等段落的表单，
   改为指向 `config/moo-monitor.php` + `MOO_MONITOR_*` env(ConfigManager 的
   扫描源加一个文件)。
7. **文档**：`docs/guide/16-cloud-push.md` 改写为"安装 moo-monitor-laravel +
   env 对照表";CHANGELOG 注明 breaking:env 改名、本地数据搬迁。

## 8. 宿主迁移指南（scaffold 3.8.x → 3.9.0）

提供 `php artisan moo:monitor:migrate`（新包内置，幂等）：

1. 检测旧目录 `scaffold/runtimes/`、`scaffold/sql-slows/`（含 ≤3.8 的 base_path 布局）；
2. 把 YAML 平移到 `storage/moo-monitor/` 对应桶（同 hash 已存在则按 `meta.updated_at` 新者胜）；
3. 迁移游标 `storage/app/scaffold/cloud-sync.json` → `storage/moo-monitor/cloud-sync.json`;
4. 删除旧目录，并提示从 .gitignore 清理旧条目；
5. 提示 env 改名清单（扫描 .env 中残留的 SCAFFOLD_RUNTIME/SQL_SLOW/CLOUD 变量并列出）。

人工步骤（写进 README）：

```bash
composer require charsen/moo-monitor-laravel   # 或随 scaffold 3.9.0 自动带入
# .env: SCAFFOLD_CLOUD_* → MOO_MONITOR_CLOUD_* (对照表见 §4)
php artisan moo:monitor:migrate
php artisan moo:cloud:push --dry-run            # 验证管道
# bootstrap/app.php 中旧的 ExceptionDispatcher reportable 接入可删(留着也不会双计)
```

**升级顺序约束**（防双重捕获）：宿主必须把 scaffold 升到 3.9.0（旧采集代码已删）
**同时**装好本包 —— 由于 3.9.0 直接 require 本包，composer 一次 update 即原子完成，
不存在中间态。只有「手动单独装本包 + scaffold 停在 3.8」才会双采集，README 中警告。

## 9. 测试与发布计划

| 阶段 | 内容 |
|---|---|
| ① 包骨架 | composer.json / Provider / config / pint / phpunit.xml,Testbench 跑通空测试 |
| ② 平移采集层 | Recorder + Concerns + ExceptionDispatcher + 测试，全绿 |
| ③ 平移云端层 | CloudClient + CloudSync + 2 个命令 + 测试，全绿；MCP 用真实 token 冒烟 |
| ④ 新增 migrate 命令 | + 测试（fixture 用 wisdomcity 的真实 YAML 样本脱敏后做用例） |
| ⑤ scaffold 3.9.0 | 按 §7 清单改造，scaffold 全量测试 + Playwright e2e 回归 |
| ⑥ 真实宿主验证 | 选一个宿主（如 wisdomcity）走一遍 §8 迁移，验证云端数据连续性（hash 不变，云端 upsert 无感） |
| ⑦ 发布 | 本包 0.1.0 tag → scaffold 3.9.0 tag → 文档/CHANGELOG |

验收标准：

- 纯净 Laravel 12 项目（无 scaffold）`composer require` 本包后，制造一个异常 +
  一条慢 SQL,`moo:cloud:push` 后在云端可见、心跳正常、MCP 六个工具可用；
- scaffold 3.9.0 宿主升级后，/scaffold 首页云端面板、/scaffold/cloud 控制台、
  302 跳转全部正常；
- 同一异常的 hash 与 3.8 时代一致（云端记录延续，不产生重复条目）。

## 10. 明确不做（本次）

- 不新增采集通道（队列失败、计划任务失败、HTTP 客户端慢请求等 → 后续版本）；
- 不做 SCAFFOLD_* env 兼容回落（只做检测提醒）；
- 不在本包内做任何 Web UI / 路由（headless；查看统一走云端，scaffold 的面板是 scaffold 自己的事）；
- 不改云端任何代码与契约。
