#!/usr/bin/env bash
#
# 维护者发版自检：在「低于开发/测试目标」的 Laravel 版本上跑一次接入冒烟。
#
# 为什么需要：Pest 测试套件只在 testbench ^10（= Laravel 12）上跑，所以「只在新版本
# 存在的 API」造成的兼容性问题，能测试全绿却在老宿主上 fatal（例如 Laravel 9 才有的
# Http::retry(..., throw:) 命名参数在 L8 上 `Unknown named parameter $throw`）。本脚本
# 临时建一个目标版本的 Laravel app、用 path 仓库装本包，断言：provider 能 boot、命令
# 注册、Laravel 8 的 Finished-only 调度失败仍能采集、`moo:cloud:test` 在打不可达云端时不 PHP fatal
# （即真正执行了 retry()/Http 路径）。
#
# 用法：
#   scripts/smoke-lower-laravel.sh [laravel/laravel 约束]
#     默认 '^8.0'（最低支持、风险最高）。也可：'^9.0' / '^10.0' / '^11.0'
#   或：composer smoke:lower            # 默认 L8
#       composer smoke:lower -- '^9.0'  # 指定版本
#
# 退出码：0 = 通过；非 0 = 某项断言失败（打印现场）。
set -euo pipefail

CONSTRAINT="${1:-^8.0}"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Laravel 8/9 在 PHP 8.2+ 上会刷弃用告警，屏蔽掉只看真 fatal
ER='error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'

# EOL 的 Laravel（9 / 10 已停止维护）框架版本会被 Composer 2.9 的安全公告拦下、装不进来。
# 这是抛弃式测试 app，只为验证本包能否在该框架版本上 boot/跑，故绕过框架层的公告拦截。
# 用 env 而非 --no-security-blocking flag：老版本 Composer 会无害忽略该 env，不会报错。
export COMPOSER_NO_SECURITY_BLOCKING=1

echo "==> 冒烟目标：laravel/laravel ${CONSTRAINT}"
echo "==> 被测包：${PKG_DIR}"

echo "==> [1/5] 建临时 Laravel app"
COMPOSER_MEMORY_LIMIT=-1 composer create-project "laravel/laravel:${CONSTRAINT}" "$WORK/app" \
  --no-interaction --prefer-dist --quiet

cd "$WORK/app"
echo "==> [2/5] 用 path 仓库安装本包"
composer config repositories.monitor \
  "{\"type\":\"path\",\"url\":\"${PKG_DIR}\",\"options\":{\"symlink\":false}}" >/dev/null
COMPOSER_MEMORY_LIMIT=-1 composer require "charsen/moo-monitor-laravel:@dev" -W --no-interaction --quiet

LARAVEL_VER="$(php -d "$ER" artisan --version 2>/dev/null)"
echo "    实际：${LARAVEL_VER}"

echo "==> [3/5] 断言 provider boot + 4 个命令注册"
LIST="$(php -d "$ER" artisan list 2>/dev/null)"
for cmd in moo:cloud:push moo:cloud:test moo:cloud:mcp moo:monitor:migrate; do
  echo "$LIST" | grep -q "$cmd" || { echo "✗ 失败：命令 ${cmd} 未注册（provider 未能 boot？）"; exit 1; }
done
echo "    ok：4 个命令均注册"

echo "==> [4/5] 断言低版本 Finished-only 非零退出仍采集一次"
php -d "$ER" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config()->set("moo-monitor.runtime.enabled", true);
// 本段由 php -r 驱动，合成异常 trace 必然含 Command line code；关闭对应实验防噪，避免测试载体干扰。
config()->set("moo-monitor.exception.cli_experiment_skip", false);
$base = storage_path("framework/testing/moo-monitor-schedule-smoke");
$recorder = new Mooeen\Monitor\Recorder\RuntimeErrorRecorder($base, ["enabled" => true]);
$app->instance(Mooeen\Monitor\Recorder\RuntimeErrorRecorder::class, $recorder);
$task = $app->make(Illuminate\Console\Scheduling\Schedule::class)->exec("smoke:failed");
$task->exitCode = 2;
event(new Illuminate\Console\Events\ScheduledTaskFinished($task, 0.1));
$rows = $recorder->list("open");
$data = count($rows) === 1 ? $recorder->get((string) ($rows[0]["hash"] ?? "")) : null;
if (count($rows) !== 1 || ($data["meta"]["source"] ?? null) !== "schedule_exit") {
    fwrite(STDERR, "Finished-only schedule_exit smoke failed: rows=" . json_encode($rows) . "; data=" . json_encode($data) . "\n");
    exit(1);
}
'
echo "    ok：Finished-only 非零退出采集一次"

echo "==> [5/5] 断言 moo:cloud:test 打不可达云端时不 PHP fatal（真跑 retry()/Http）"
OUT="$(MOO_MONITOR_CLOUD_ENABLED=true MOO_MONITOR_CLOUD_TOKEN=moo_smoke \
       MOO_MONITOR_CLOUD_URL=http://127.0.0.1:9 MOO_MONITOR_CLOUD_TIMEOUT=2 \
       php -d "$ER" artisan moo:cloud:test --type=runtimes 2>&1)" || true
if echo "$OUT" | grep -qiE 'PHP Fatal|PHP Parse|Uncaught|Unknown named parameter|ArgumentCountError'; then
  echo "✗ 失败：检测到 PHP fatal —"
  echo "$OUT"
  exit 1
fi
echo "$OUT" | grep -q '心跳' || { echo "✗ 失败：自检未进入心跳阶段（命令可能根本没跑起来）—"; echo "$OUT"; exit 1; }
echo "    ok：无 fatal，已执行到心跳/重试路径"

echo "==> 通过：本包在 ${LARAVEL_VER} 上能装、能 boot、能跑。"
