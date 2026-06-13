<?php declare(strict_types=1);

namespace Mooeen\Monitor\Recorder\Concerns;

use Symfony\Component\Yaml\Yaml;

/**
 * 三桶(open/resolved/deleted)YAML 记录器共享的低层文件 IO 原语。
 * RuntimeErrorRecorder / SqlSlowRecorder 是同骨架的两个 fork,这几个方法原本逐字拷贝两份;
 * 收口成单一真源(SqlSlow 一度从 Runtime 漂移过,见 2026-05-29 原子性 backport)。
 *
 * 消费类须提供以下 hook(各类实现不同,故不进 trait):
 *   - string $basePath
 *   - path(string $key, string $bucket): string         // key 校验各异(runtime/sql-slow hash)
 *   - readFile(string $key, string $bucket): ?array      // Runtime 带空文件腐败日志,SqlSlow 简版
 *   - ensureDir(): void
 *   - forgetOpenCountCache(): void
 *   - nowIso(): string
 */
trait WritesBucketedYaml
{
    private function writeFile(string $key, string $bucket, array $data): bool
    {
        // 写盘前刷 meta.updated_at,让 ScaffoldMergeYamlCommand 在多端 push 冲突时 last-write-wins 合并
        $data['meta'] = array_merge($data['meta'] ?? [], ['updated_at' => $this->nowIso()]);
        $yaml         = Yaml::dump(
            $data,
            8,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE
        );
        // 原子写:先写同目录临时文件,再 rename 覆盖(同 fs 的 rename 原子)。
        // 防两种损坏:① 崩溃/磁盘满留半截或 0 字节 yaml;② 每分钟的 moo:cloud:push 进程读到
        // 正在被重写的文件 → 解析失败被静默跳过 → 那条变更可能再不重试(唯一真实丢数据路径)。
        // 并发同 hash 写各自用唯一临时名,rename 后最多 last-write-wins,但读者永远见到完整文件。
        $target = $this->path($key, $bucket);
        $tmp    = $target . '.tmp' . bin2hex(random_bytes(4));
        $ok     = false;
        if (@file_put_contents($tmp, $yaml) !== false) {
            if (@rename($tmp, $target)) {
                $ok = true;
            } else {
                @unlink($tmp);   // rename 失败(跨设备等罕见)→ 清理临时文件,原文件保持不动
            }
        }
        $this->forgetOpenCountCache();

        return $ok;
    }

    private function moveFile(string $key, string $from, string $to): bool
    {
        $src = $this->path($key, $from);
        $dst = $this->path($key, $to);
        if (! is_file($src)) {
            return false;
        }
        $this->ensureDir();
        $ok = @rename($src, $dst);
        if ($ok) {
            $this->forgetOpenCountCache();
        }

        return $ok;
    }

    private function find(string $key): ?array
    {
        return $this->readFile($key, 'open')
            ?? $this->readFile($key, 'resolved')
            ?? $this->readFile($key, 'deleted');
    }

    private function countOpen(): int
    {
        $dir = $this->basePath . '/open';
        if (! is_dir($dir)) {
            return 0;
        }

        $n = 0;
        foreach (glob($dir . '/*.yaml') ?: [] as $file) {
            if ($this->isValidHash(basename($file, '.yaml'))) {
                $n++;
            }
        }

        return $n;
    }
}
