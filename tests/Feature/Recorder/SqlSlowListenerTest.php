<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Http;
use Mooeen\Monitor\Recorder\SqlSlowListener;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Mooeen\Monitor\Tests\TestCase;

/**
 * plan 52:慢 SQL 监听器单测
 *   - enabled / threshold / skip_patterns 三种早返
 *   - 钉钉签名复用 exception 的 token+secret
 *   - hash 聚合(同一 SQL 不同 binding count++)
 *   - binding 替换避开 %Y%m 格式符
 */
class SqlSlowListenerTest extends TestCase
{
    private string $tmpDir;

    private SqlSlowRecorder $recorder;

    private SqlSlowListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/scaffold-sql-slow-test-' . uniqid();
        @mkdir($this->tmpDir, 0775, true);

        config()->set('moo-monitor.sql_slow.enabled', true);
        config()->set('moo-monitor.sql_slow.threshold_ms', 100);
        config()->set('moo-monitor.sql_slow.path', $this->tmpDir);
        config()->set('moo-monitor.sql_slow.max_open', 500);
        config()->set('moo-monitor.sql_slow.skip_patterns', [
            'insert into `system_operation_logs`',
        ]);
        // 把 SqlSlowRecorder 重 bind 一份指到 tmpDir(原 singleton 已用 default config 装好)
        $this->recorder = new SqlSlowRecorder($this->tmpDir, config()->get('moo-monitor.sql_slow'));
        $this->listener = new SqlSlowListener($this->recorder);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function fakeEvent(string $sql, array $bindings, float $timeMs): QueryExecuted
    {
        // QueryExecuted 公开属性:$sql / $bindings / $time / $connection / $connectionName
        return new QueryExecuted($sql, $bindings, $timeMs, app('db')->connection());
    }

    public function test_skips_when_disabled(): void
    {
        config()->set('moo-monitor.sql_slow.enabled', false);
        $this->listener->handle($this->fakeEvent('select 1', [], 999));
        $this->assertSame(0, $this->recorder->count('open'));
    }

    public function test_skips_when_under_threshold(): void
    {
        $this->listener->handle($this->fakeEvent('select 1', [], 50.0));
        $this->assertSame(0, $this->recorder->count('open'));
    }

    public function test_skip_patterns_drops_match(): void
    {
        $this->listener->handle($this->fakeEvent(
            'insert into `system_operation_logs` (`action`) values (?)',
            ['x'],
            300.0
        ));
        $this->assertSame(0, $this->recorder->count('open'));
    }

    public function test_records_when_over_threshold(): void
    {
        $this->listener->handle($this->fakeEvent(
            'select count(*) from `users` where `id` = ?',
            [42],
            250.5
        ));
        $this->assertSame(1, $this->recorder->count('open'));
    }

    public function test_same_query_different_binding_aggregates_count(): void
    {
        // hash 公式是 normalized_sql + file:line(触发点),所以 3 次 handle 必须在同一 source line
        // 调用才会聚合到同一 hash(用 helper closure 包一层强制相同行号)
        $sql  = 'select * from `t` where `id` = ?';
        $fire = fn ($binding, $ms) => $this->listener->handle($this->fakeEvent($sql, [$binding], $ms));

        $fire(1, 200.0);
        $fire(2, 350.0);
        $fire(3, 150.0);

        $this->assertSame(1, $this->recorder->count('open'));
        $rows = $this->recorder->list('open');
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['count']);
        // max_ms 应该是 350(最长那次)
        $this->assertSame(350.0, (float) $rows[0]['max_ms']);
    }

    public function test_binding_replace_preserves_percent_format(): void
    {
        // DATE_FORMAT 里的 %Y / %m 不应该被 vsprintf 误当格式符 — 改用顺序替换
        $this->listener->handle($this->fakeEvent(
            "select DATE_FORMAT(`created_at`, '%Y-%m-%d') from `t` where `id` = ?",
            [42],
            200.0
        ));
        $rows = $this->recorder->list('open');
        $this->assertNotEmpty($rows);

        $hash = $rows[0]['hash'];
        $data = $this->recorder->get($hash);
        // sql.last 含 binding 替换后的字面值 + 完整 DATE_FORMAT 字面量
        $this->assertStringContainsString('%Y-%m-%d', $data['sql']['last']);
        $this->assertStringContainsString('42', $data['sql']['last']);
    }

    public function test_yaml_records_original_bytes_for_truncation_judgement(): void
    {
        // sql.{raw,last}_bytes = 原始字符串长度(truncate 前),让 user 通过对比 mb_strlen(sql.raw) vs raw_bytes
        // 一眼判断是否被 truncate(4096) 截过 — 防 user 看到短 SQL 误以为被截
        $shortSql = 'select sleep(0.3) as t';
        $this->listener->handle($this->fakeEvent($shortSql, [], 200.0));

        $row = $this->recorder->list('open')[0] ?? null;
        $this->assertNotNull($row);
        $data = $this->recorder->get($row['hash']);

        $this->assertSame(mb_strlen($shortSql), $data['sql']['raw_bytes']);
        $this->assertSame(mb_strlen($shortSql), $data['sql']['last_bytes']);
        // deriveRow 派生字段也带上(列表页 sql_bytes 列依赖)
        $this->assertSame(mb_strlen($shortSql), $row['sql_bytes']);
    }

    public function test_records_but_sends_nothing(): void
    {
        // 3.7.0:慢查询仅落盘,本地不再发任何钉钉;通知由云端在 intake 时触发。
        Http::fake();
        $this->listener->handle($this->fakeEvent(
            'select count(*) from `users` where `deleted_at` is null',
            [],
            347.22
        ));

        $this->assertSame(1, $this->recorder->count('open'));
        Http::assertNothingSent();
    }
}
