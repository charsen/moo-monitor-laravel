<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Http\Request;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * payload 截断回归锁(审查 #7 / #11):
 *
 * #7 maskRecursive 原用字节级 substr 截断,中文/emoji 截在 UTF-8 字符中间会产出非法串,被 symfony/yaml
 *    整段当二进制 base64 编码(落盘与云端展示成一坨不可读 base64)。本包面向中文团队,payload 含中文是常态。
 * #11 string_truncate 配 <20 时,substr 长度 cap-20 为负,PHP 负长度语义反而放大输出且计数错乱。
 *
 * 现在:字符级 mb_substr + max(0, cap-20),与 SqlSlowRecorder::truncate 对齐。
 *
 * 校验思路:解析落盘 yaml 取目标 payload 字段。若曾被字节切断成非法 UTF-8,symfony 会 base64 编码、
 * 解析回来就是原始非法字节 → mb_check_encoding 为 false。所以「解析后仍是合法 UTF-8 字符串」即证明未损坏。
 */
class RecorderPayloadTruncateTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/moo-trunc-' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->base . '/open/*.yaml') ?: [] as $f) {
            @unlink($f);
        }
        foreach (['open', 'resolved', 'deleted'] as $b) {
            @rmdir($this->base . '/' . $b);
        }
        @unlink($this->base . '/.gitignore');
        @rmdir($this->base);
        parent::tearDown();
    }

    /** 落盘后解析 yaml,返回指定 payload 字段值。 */
    private function recordAndReadField(array $config, string $key, string $value): mixed
    {
        $r    = new RuntimeErrorRecorder($this->base, ['enabled' => true] + $config);
        $req  = Request::create('/x', 'POST', [$key => $value]);
        $hash = $r->record(new RuntimeException('payload truncation case'), $req);
        $this->assertNotNull($hash);

        $data = Yaml::parse((string) file_get_contents($this->base . '/open/' . $hash . '.yaml'));

        return $data['payload'][$key] ?? null;
    }

    public function test_long_multibyte_payload_truncates_to_valid_utf8(): void
    {
        $out = $this->recordAndReadField(['string_truncate' => 50], 'note', str_repeat('中', 300));

        $this->assertIsString($out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), '截断后必须是合法 UTF-8(未被字节切断成 binary)');
        $this->assertStringContainsString('…', $out, '应带截断标记');
    }

    public function test_tiny_string_truncate_below_20_does_not_inflate_output(): void
    {
        // cap<20:旧实现 cap-20 为负 → 输出反而比 cap 更长且计数错乱。现在 max(0,…) 兜底。
        $input = str_repeat('a', 200);
        $out   = $this->recordAndReadField(['string_truncate' => 10], 'blob', $input);

        $this->assertIsString($out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'));
        $this->assertLessThan(mb_strlen($input), mb_strlen($out), '截断结果必须明显短于原始输入,不再反向放大');
    }
}
