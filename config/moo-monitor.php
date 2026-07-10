<?php declare(strict_types=1);

/*
 * moo-monitor-laravel 配置
 *
 * 发布到宿主：php artisan vendor:publish --tag=moo-monitor-config
 * 所有路径：绝对路径原样使用；相对路径挂在 storage_path() 下（目录自带 .gitignore，不入宿主 git）。
 */
return [

    // ── 运行时异常采集 ───────────────────────────────────────────────────
    'runtime' => [
        'enabled' => env('MOO_MONITOR_RUNTIME_ENABLED', true),

        // YAML 本地缓冲目录(相对 storage_path())
        'path' => env('MOO_MONITOR_RUNTIME_PATH', 'moo-monitor/runtimes'),

        // open 桶最大条数（写盘闸：超过后新 hash 不再落盘）
        'max_open' => (int) env('MOO_MONITOR_RUNTIME_MAX_OPEN', 500),

        // 同一 hash 每天最多写盘次数（冻结 yaml，止住热错误反复推送）；<=0 不限制
        'daily_cap' => (int) env('MOO_MONITOR_RUNTIME_DAILY_CAP', 10),

        // 脱敏关键字（URL query / payload 键名 / SQL 列名，子串匹配、大小写不敏感）
        'mask_keys' => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],

        // 采集出错用户时依次尝试的 auth guard（命中第一个有登录用户的即用）。默认 admin/user/web；
        // 宿主用 api / sanctum / 自定义 guard 时在此追加，否则采不到用户。
        'auth_guards' => ['admin', 'user', 'web'],

        // payload 单字段截断长度
        'string_truncate' => 200,

        // trace 字段最大字节数
        'trace_max_bytes' => 65536,

        // 源码片段 ±N 行
        'snippet_lines' => 10,

        // 调用栈里「算宿主业务代码」的相对路径前缀（trace.app_frames 过滤用）。默认只认 app/ + routes/；
        // 用 Modules/、src/、database/ 等布局的宿主自行追加，否则这些帧不会进 app_frames。
        'app_frame_prefixes' => ['app/', 'routes/'],

        // open 数缓存 TTL（秒）
        'cache_ttl' => (int) env('MOO_MONITOR_RUNTIME_CACHE_TTL', 30),
    ],

    // ── 异常分发行为 ─────────────────────────────────────────────────────
    'exception' => [
        // Provider 自动把 ExceptionDispatcher 挂到 host 的 reportable 链；
        // false 时退回宿主在 bootstrap/app.php 手动接入（两者并存不会双计）。
        'auto_hook' => true,

        // 捕获 Log::error(..., ['exception' => $e]) 这类“只写日志、未进入 reportable”的异常。
        // 队列 failed 回调 / 业务兜底 catch 常会这样记录异常；不开这条会只留 laravel.log，云端 runtimes 看不到。
        'log_context_hook' => (bool) env('MOO_MONITOR_EXCEPTION_LOG_CONTEXT_HOOK', true),

        // 捕获 Log::error($e) / Log::error('失败: '.$e->getMessage()) 这类“字符串化异常进日志”：
        // Logger::formatMessage 在事件之前把 Throwable 强转 string，context 无 exception 对象，
        // log_context 钩子全漏。开启后按调用点合成一条记录进同一管道（source=log_message）。
        'log_message_hook' => (bool) env('MOO_MONITOR_EXCEPTION_LOG_MESSAGE_HOOK', true),

        // 触发上述两个日志钩子的日志级别白名单。默认只兜 error 及以上；想兜 warning 级的宿主自行加。
        'log_context_levels' => ['error', 'critical', 'alert', 'emergency'],

        // 捕获 abort(500/502/503) 与第三方包抛的 HttpException 5xx：这类在框架 internalDontReport 里，
        // reportable 主链看不见（“服务对外已在冒烟”的高价值信号）。挂 renderable 观察者补采，只读、放行默认渲染，不改宿主响应。
        'http_5xx_hook' => (bool) env('MOO_MONITOR_EXCEPTION_HTTP_5XX_HOOK', true),

        // 捕获 Laravel 队列 JobFailed 事件，补齐 failed_jobs / failed 回调里未显式 report($e) 的失败。
        'queue_failed_hook' => (bool) env('MOO_MONITOR_EXCEPTION_QUEUE_FAILED_HOOK', true),

        // 捕获 exec 型调度任务的非零退出码（不抛异常、只以退出码表示失败，过去完全不可见）。
        // 监听 ScheduledTaskFinished / ScheduledBackgroundTaskFinished，退出码非 0 时合成一条 schedule_exit 记录。
        'schedule_exit_hook' => (bool) env('MOO_MONITOR_EXCEPTION_SCHEDULE_EXIT_HOOK', true),

        // php -r / tinker 里的实验异常跳过
        'cli_experiment_skip' => true,

        // Symfony Console 用法错（命令不存在 / 参数缺失等）跳过
        'console_input_skip' => true,
    ],

    // ── 慢 SQL 采集 ──────────────────────────────────────────────────────
    'sql_slow' => [
        'enabled' => (bool) env('MOO_MONITOR_SQL_SLOW_ENABLED', false),

        // 慢 SQL 阈值（毫秒）
        'threshold_ms' => (int) env('MOO_MONITOR_SQL_SLOW_THRESHOLD_MS', 100),

        // YAML 本地缓冲目录(相对 storage_path())
        'path' => env('MOO_MONITOR_SQL_SLOW_PATH', 'moo-monitor/sql-slows'),

        // 脱敏关键字（同 runtime.mask_keys）
        'mask_keys' => ['password', 'pwd', 'token', 'secret', 'api_key', 'authorization'],

        // 采集出错用户依次尝试的 auth guard（同 runtime.auth_guards）
        'auth_guards' => ['admin', 'user', 'web'],

        // open 桶最大条数
        'max_open' => (int) env('MOO_MONITOR_SQL_SLOW_MAX_OPEN', 500),

        // 同一 hash 每天最多写盘次数；<=0 不限制
        'daily_cap' => (int) env('MOO_MONITOR_SQL_SLOW_DAILY_CAP', 10),

        // SQL 子串命中即跳过（操作日志 / 迁移表等高频噪音）。默认避开操作日志写入 + 迁移表查询，同 3.8.15
        'skip_patterns' => [
            'insert into `system_operation_logs`',
            'select * from `migrations`',
        ],

        // open 数缓存 TTL（秒）
        'cache_ttl' => (int) env('MOO_MONITOR_SQL_SLOW_CACHE_TTL', 30),
    ],

    // ── 云端（moo-scaffold-cloud）────────────────────────────────────────
    'cloud' => [
        'enabled' => (bool) env('MOO_MONITOR_CLOUD_ENABLED', false),

        // 云端基址（私有部署时改这里）
        'base_url' => rtrim((string) env('MOO_MONITOR_CLOUD_URL', 'https://c.mooeen.com'), '/'),

        // 项目接入 token（云端「接入 Token」页生成，须带 runtimes / slow_queries 能力）
        'token' => (string) env('MOO_MONITOR_CLOUD_TOKEN', ''),

        // HTTP 超时（秒）
        'timeout' => (int) env('MOO_MONITOR_CLOUD_TIMEOUT', 5),

        // 每批推送条数
        'batch' => (int) env('MOO_MONITOR_CLOUD_BATCH', 100),

        // TLS 证书校验（内网自签可关）
        'verify' => (bool) env('MOO_MONITOR_CLOUD_VERIFY', true),

        'push' => [
            'runtimes' => (bool) env('MOO_MONITOR_CLOUD_PUSH_RUNTIMES', true),
            'slow_sql' => (bool) env('MOO_MONITOR_CLOUD_PUSH_SLOW_SQL', true),
        ],

        // enabled + schedule 同时为真时自动挂每分钟调度（需宿主跑 schedule:run）
        'schedule' => (bool) env('MOO_MONITOR_CLOUD_SCHEDULE', true),

        // 推送成功后本地回收阈值（天）：resolved 全清，open 清 last_seen 超过 N 天的；<=0 完全不回收
        'local_retention_days' => (int) env('MOO_MONITOR_CLOUD_LOCAL_RETENTION_DAYS', 7),
    ],

];
