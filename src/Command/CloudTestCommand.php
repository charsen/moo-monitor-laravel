<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Description: 云端连通性自检 —— 一键确认「采集 → 推送 → 云端」整条管道是否真的有效。
 *
 * 新手接入后最大的疑问是「我配好了吗?数据真能到云端吗?」。本命令不依赖真实异常/慢查询发生,
 * 直接推送一条可识别的自检 runtime + 一条自检慢 SQL 走真实 intake 端点,逐步反馈:
 *   ① 配置检查(地址/token/开关)
 *   ② 心跳(连通 + 鉴权)
 *   ③ 推送自检 runtime(默认保留为「待处理」,便于在云端亲眼确认数据已到达)
 *   ④ 推送自检慢 SQL
 *
 * 用法:
 *   moo:cloud:test                 完整自检(runtime + slow_sql)
 *   moo:cloud:test --type=runtimes 只测 runtime
 *   moo:cloud:test --type=slow_sql 只测慢 SQL
 *   moo:cloud:test --resolve       推送后在云端把自检 runtime 标记为已解决(默认保留可见)
 */

namespace Mooeen\Monitor\Command;

use Illuminate\Console\Command;
use Mooeen\Monitor\Cloud\CloudClient;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Throwable;

class CloudTestCommand extends Command
{
    protected $name = 'moo:cloud:test';

    protected $description = '自检:推送一条 runtime + 一条慢 SQL 到云端,验证接入配置是否真的有效';

    protected $signature = 'moo:cloud:test
        {--type=both : 自检类型:runtimes / slow_sql / both}
        {--resolve : 推送后在云端把自检 runtime 标记为已解决(默认保留为「未处理」,便于亲眼确认数据已到达)}';

    public function handle(): int
    {
        $cfg = (array) config('moo-monitor.cloud', []);

        $this->line('moo-monitor 云端连通性自检');
        $this->line(str_repeat('─', 48));

        // ① 配置检查
        $baseUrl = (string) ($cfg['base_url'] ?? '');
        $token   = (string) ($cfg['token'] ?? '');
        $enabled = (bool) ($cfg['enabled'] ?? false);

        $this->line('① 配置检查');
        $this->line('   云端地址  : ' . ($baseUrl !== '' ? $baseUrl : '<空>'));
        $this->line('   接入 token : ' . ($token !== '' ? $this->maskToken($token) : '<空>'));
        $this->line('   采集开关  : ' . ($enabled
            ? '已开启'
            : '关闭(MOO_MONITOR_CLOUD_ENABLED=false;连通性自检不受影响,但正常运行不会自动采集/推送)'));

        if ($baseUrl === '' || $token === '') {
            $this->error('✗ 云端地址或 token 未配置,无法自检。请在 .env 配置 MOO_MONITOR_CLOUD_URL / MOO_MONITOR_CLOUD_TOKEN。');

            return self::INVALID;
        }

        $type = (string) $this->option('type');
        if (! in_array($type, ['both', 'runtimes', 'slow_sql'], true)) {
            $this->error('--type 必须是 runtimes / slow_sql / both 之一。');

            return self::INVALID;
        }

        $client = new CloudClient($cfg);

        // ② 心跳:连通 + 鉴权(零数据污染的前置探测)
        $this->newLine();
        $this->line('② 连通与鉴权(心跳)');
        if (! $client->heartbeat()) {
            $this->error('   ✗ 心跳失败 —— 无法连通云端,或 token 无效。');
            $this->line('     排查:① 云端地址是否可达;② token 是否正确且具备 runtimes 能力;');
            $this->line('     ③ 内网自签证书可设 MOO_MONITOR_CLOUD_VERIFY=false。');

            return self::FAILURE;
        }
        $this->info('   ✓ 心跳正常:云端可达、token 有效。');

        $ok = true;

        // ③ 推送自检 runtime
        if ($type === 'both' || $type === 'runtimes') {
            $this->newLine();
            $this->line('③ 推送一条自检 runtime');
            $ok = $this->testRuntime($client) && $ok;
        }

        // ④ 推送自检慢 SQL
        if ($type === 'both' || $type === 'slow_sql') {
            $this->newLine();
            $this->line('④ 推送一条自检慢 SQL');
            $ok = $this->testSlowSql($client) && $ok;
        }

        $this->newLine();
        $this->line(str_repeat('─', 48));
        if ($ok) {
            $this->info('✓ 自检通过:接入配置有效,推送管道通畅。');
            $this->line('  现在去云端「云端汇聚 / runtimes / slow_queries」即可看到刚推送的自检记录。');

            return self::SUCCESS;
        }

        $this->error('✗ 自检未全部通过,请按上面的失败项排查。');

        return self::FAILURE;
    }

    private function testRuntime(CloudClient $client): bool
    {
        try {
            $rec  = app(RuntimeErrorRecorder::class)->buildSelfTestRecord();
            $hash = (string) ($rec['hash'] ?? '');

            $r = $client->send(CloudClient::PATH_RUNTIMES, [$rec]);
            if (! $r['ok']) {
                $this->error('   ✗ 推送失败:' . $r['error']);

                return false;
            }
            $this->info("   ✓ 已推送(saved={$r['saved']}, hash={$hash})。");

            if ((bool) $this->option('resolve') && $hash !== '') {
                $res = $client->resolveRuntime($hash, 'moo:cloud:test 自检记录', 'moo:cloud:test');
                $this->line($res['ok']
                    ? '   ✓ 已按 --resolve 在云端标记为已解决。'
                    : '   · 自动解决跳过:' . $res['error'] . '(记录仍保留在云端,可在 UI 手动解决)。');
            } else {
                $this->line('   · 记录保留在云端「未处理」,去 runtimes 列表即可亲眼确认数据已到达;确认后可在 UI 解决,或加 --resolve 让本命令自动解决。');
            }

            return true;
        } catch (Throwable $e) {
            $this->error('   ✗ 异常:' . $e->getMessage());

            return false;
        }
    }

    private function testSlowSql(CloudClient $client): bool
    {
        try {
            $rec  = app(SqlSlowRecorder::class)->buildSelfTestRecord();
            $hash = (string) ($rec['hash'] ?? '');

            $r = $client->send(CloudClient::PATH_SLOW_QUERIES, [$rec]);
            if (! $r['ok']) {
                $this->error('   ✗ 推送失败:' . $r['error']);

                return false;
            }
            $this->info("   ✓ 已推送(saved={$r['saved']}, hash={$hash})。");
            $this->line('   · 慢 SQL 无解决接口,这条自检记录会留在云端(重复自检只 upsert 同一条,不堆积),可在 UI 忽略/解决。');

            return true;
        } catch (Throwable $e) {
            $this->error('   ✗ 异常:' . $e->getMessage());

            return false;
        }
    }

    /** token 打码展示:首尾各留 4 位,中间打星,避免完整 token 出现在终端/CI 日志。 */
    private function maskToken(string $token): string
    {
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }

        return substr($token, 0, 4) . str_repeat('*', min(12, $len - 8)) . substr($token, -4);
    }
}
