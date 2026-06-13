<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * 复发重开回归锁(审查 #8):
 *
 * record() 原本只对 resolved 桶做 reopen,对 deleted 桶漏处理 —— deleted 里的同 hash 复发时会被当普通
 * 已存在记录写进 open,留下「同一 {hash}.yaml 同时存在 open 与 deleted 两桶」的跨桶重复:破坏「相同问题
 * 按 hash 聚合」不变量(count 被劈成两份、list('all') 把一个问题算两个),还把已软删问题悄悄推回云端。
 * 从 moo-scaffold ≤3.8 迁移、deleted 桶有历史记录的团队尤其会踩到。
 *
 * 现在:resolved / deleted 一视同仁,从记录实际所在桶搬回 open 复活,绝不留跨桶重复。
 */
class RecorderReopenBucketTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/moo-reopen-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (['open', 'resolved', 'deleted'] as $b) {
            foreach (glob($this->base . '/' . $b . '/*.yaml') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->base . '/' . $b);
        }
        @unlink($this->base . '/.gitignore');
        @rmdir($this->base);
        parent::tearDown();
    }

    /** 把 open/<hash>.yaml 搬到指定桶,并把 yaml 内 status 字段同步改成该桶名。 */
    private function moveToBucket(string $hash, string $bucket): void
    {
        @mkdir($this->base . '/' . $bucket, 0775, true);
        $src            = $this->base . '/open/' . $hash . '.yaml';
        $data           = Yaml::parse((string) file_get_contents($src));
        $data['status'] = $bucket;
        file_put_contents($this->base . '/' . $bucket . '/' . $hash . '.yaml', Yaml::dump($data, 8, 2));
        @unlink($src);
    }

    public function test_recurrence_from_deleted_bucket_reopens_without_cross_bucket_duplicate(): void
    {
        $r = new RuntimeErrorRecorder($this->base, ['enabled' => true, 'max_open' => 500]);
        $e = new RuntimeException('recurring boom'); // 同一对象 → 同一 hash

        $hash = $r->record($e);
        $this->assertNotNull($hash);
        $this->moveToBucket($hash, 'deleted');

        // 同一异常再次触发:必须从 deleted 搬回 open 复活,而不是在 open 另建一份。
        $again = $r->record($e);

        $this->assertSame($hash, $again);
        $this->assertTrue(is_file($this->base . '/open/' . $hash . '.yaml'), 'open 桶应有该记录');
        $this->assertFalse(is_file($this->base . '/deleted/' . $hash . '.yaml'), 'deleted 桶不应再保留(避免跨桶重复)');
        $this->assertSame(1, $r->count(), '同一问题在所有桶合计只应有 1 条');
    }

    public function test_recurrence_from_resolved_bucket_reopens_and_counts_up(): void
    {
        $r = new RuntimeErrorRecorder($this->base, ['enabled' => true, 'max_open' => 500]);
        $e = new RuntimeException('resolved then recurs');

        $hash = $r->record($e);
        $this->moveToBucket($hash, 'resolved');

        $again = $r->record($e);

        $this->assertSame($hash, $again);
        $this->assertTrue(is_file($this->base . '/open/' . $hash . '.yaml'));
        $this->assertFalse(is_file($this->base . '/resolved/' . $hash . '.yaml'));

        $data = $r->get($hash);
        $this->assertSame('open', $data['status']);
        $this->assertSame(2, (int) $data['count'], 'reopen 应 count+1');
        $this->assertNull($data['resolved_at'], 'reopen 应清掉 resolved_* 字段');
    }
}
