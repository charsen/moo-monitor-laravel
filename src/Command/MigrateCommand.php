<?php declare(strict_types=1);

/*
 * @Author: Charsen
 * @Date: 2026-06-12
 * @Description: 从 moo-scaffold ≤3.8 迁移本地监控数据到 moo-monitor-laravel 的新布局
 *
 * 做三件事(全部幂等,可反复跑):
 *   1. 旧 YAML 平移:base_path('scaffold/{runtimes,sql-slows}') 三桶 → storage/moo-monitor/ 对应桶;
 *      同 hash 已存在时按 meta.updated_at 新者胜,旧者丢弃。
 *   2. 推送游标平移:storage/app/scaffold/cloud-sync.json → storage/moo-monitor/cloud-sync.json;
 *      两边都有时逐类型取较新水位合并(游标偏旧只会多推,幂等无害;偏新才会漏)。
 *   3. .env 体检:列出仍残留的 SCAFFOLD_RUNTIME/SQL_SLOW/CLOUD 变量与新名对照(只提示,不改文件)。
 */

namespace Mooeen\Monitor\Command;

use Illuminate\Console\Command;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Throwable;

class MigrateCommand extends Command
{
    protected $name = 'moo:monitor:migrate';

    protected $description = '从 moo-scaffold ≤3.8 迁移本地监控数据与推送游标到 storage/moo-monitor/(幂等)';

    protected $signature = 'moo:monitor:migrate
        {--from-runtimes=scaffold/runtimes : 旧 runtime YAML 目录(相对项目根)}
        {--from-sql-slows=scaffold/sql-slows : 旧慢 SQL YAML 目录(相对项目根)}
        {--dry-run : 只报告将执行的动作,不实际移动}';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $pairs = [
            'runtimes' => [
                base_path((string) $this->option('from-runtimes')),
                RuntimeErrorRecorder::resolveStoragePath((string) config('moo-monitor.runtime.path', 'moo-monitor/runtimes')),
            ],
            'sql-slows' => [
                base_path((string) $this->option('from-sql-slows')),
                RuntimeErrorRecorder::resolveStoragePath((string) config('moo-monitor.sql_slow.path', 'moo-monitor/sql-slows')),
            ],
        ];

        $somethingDone = false;
        foreach ($pairs as $label => [$from, $to]) {
            $somethingDone = $this->migrateBuckets($label, $from, $to, $dryRun) || $somethingDone;
        }

        $somethingDone = $this->migrateCursor($dryRun) || $somethingDone;

        $this->checkEnv();

        if (! $somethingDone) {
            $this->info('没有发现需要迁移的旧数据(已迁移过或从未使用本地缓冲)。');
        } elseif ($dryRun) {
            $this->info('dry-run 完成,以上动作未实际执行。');
        } else {
            $this->info('迁移完成。建议接着跑:php artisan moo:cloud:push --dry-run 验证推送管道。');
        }

        return self::SUCCESS;
    }

    /** 平移一类记录的三桶 yaml。返回是否有动作。 */
    private function migrateBuckets(string $label, string $from, string $to, bool $dryRun): bool
    {
        if (! is_dir($from)) {
            return false;
        }
        // 防自迁:--from-* 指到新位置(或宿主已自定义为同一目录)时跳过
        if (rtrim($from, '/') === rtrim($to, '/')) {
            return false;
        }

        $moved   = 0;
        $skipped = 0;
        foreach (['open', 'resolved', 'deleted'] as $bucket) {
            $srcDir = $from . '/' . $bucket;
            $dstDir = $to . '/' . $bucket;
            $files  = glob($srcDir . '/*.yaml') ?: [];
            if ($files === []) {
                continue;
            }
            if (! $dryRun) {
                $this->ensureDirWithGitignore($to);
                if (! is_dir($dstDir)) {
                    @mkdir($dstDir, 0775, true);
                }
            }
            foreach ($files as $src) {
                $dst = $dstDir . '/' . basename($src);
                if (is_file($dst) && ! $this->srcIsNewer($src, $dst)) {
                    // 新位置已有同 hash 且不旧于旧文件 → 丢弃旧文件
                    if (! $dryRun) {
                        @unlink($src);
                    }
                    $skipped++;

                    continue;
                }
                if (! $dryRun) {
                    if (! @rename($src, $dst)) {
                        // 跨设备等罕见场景 rename 失败 → copy + unlink 兜底
                        if (@copy($src, $dst)) {
                            @unlink($src);
                        } else {
                            $this->warn("移动失败,已跳过:{$src}");

                            continue;
                        }
                    }
                }
                $moved++;
            }
        }

        if ($moved === 0 && $skipped === 0) {
            // 没有 yaml 可迁,但旧布局空壳(仅 .gitkeep / .gitignore 残留)也该清 —— 支持重跑补清
            if (! $dryRun) {
                $this->removeDirIfEmpty($from);
                if (! is_dir($from)) {
                    $this->line("[{$label}] 旧目录空壳已清理:" . $this->shortPath($from));

                    return true;
                }
            }

            return false;
        }

        $this->line(sprintf(
            '%s[%s] %s → %s:迁移 %d 条%s',
            $dryRun ? '(dry-run)' : '',
            $label,
            $this->shortPath($from),
            $this->shortPath($to),
            $moved,
            $skipped > 0 ? ",丢弃旧重复 {$skipped} 条" : '',
        ));

        if (! $dryRun) {
            $this->removeDirIfEmpty($from);
        }

        return true;
    }

    /** 平移/合并推送游标。返回是否有动作。 */
    private function migrateCursor(bool $dryRun): bool
    {
        $old = storage_path('app/scaffold/cloud-sync.json');
        $new = storage_path('moo-monitor/cloud-sync.json');
        if (! is_file($old)) {
            return false;
        }

        $oldState = $this->readJson($old);
        $newState = is_file($new) ? $this->readJson($new) : [];

        // 逐类型取较新水位:游标偏旧只是多推一遍(云端幂等),偏新才会漏 —— 永远保守取大。
        $merged = $newState;
        foreach ($oldState as $type => $cursor) {
            $oldEpoch = $this->epoch((string) $cursor);
            $newEpoch = $this->epoch((string) ($newState[$type] ?? ''));
            if ($oldEpoch > $newEpoch) {
                $merged[$type] = $cursor;
            }
        }

        if (! $dryRun) {
            $this->ensureDirWithGitignore(dirname($new));
            @file_put_contents($new, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            @unlink($old);
            @unlink($old . '.lock'); // writeState 的 flock 锁文件,跟游标一起清
            $this->removeDirIfEmpty(dirname($old));
        }

        $this->line(($dryRun ? '(dry-run)' : '') . '[cursor] ' . $this->shortPath($old) . ' → ' . $this->shortPath($new));

        return true;
    }

    /** .env 旧变量体检:只提示改名对照,不改文件。 */
    private function checkEnv(): void
    {
        $envFile = base_path('.env');
        if (! is_file($envFile)) {
            return;
        }
        $lines = @file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
        $found = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(SCAFFOLD_(?:RUNTIME|SQL_SLOW|CLOUD)_[A-Z_]+)\s*=/', $line, $m)) {
                $found[] = $m[1];
            }
        }
        if ($found === []) {
            return;
        }

        $this->newLine();
        $this->warn('.env 中发现旧 SCAFFOLD_* 变量(已不生效),请改名:');
        $this->table(
            ['旧变量', '新变量'],
            array_map(fn ($k) => [$k, preg_replace('/^SCAFFOLD_/', 'MOO_MONITOR_', $k)], $found),
        );
    }

    // ── 工具 ─────────────────────────────────────────────────────────────

    /** 按 meta.updated_at(回退 last_seen,再回退 mtime)比较新旧。 */
    private function srcIsNewer(string $src, string $dst): bool
    {
        return $this->recordEpoch($src) > $this->recordEpoch($dst);
    }

    private function recordEpoch(string $file): float
    {
        try {
            $rec = \Symfony\Component\Yaml\Yaml::parse((string) @file_get_contents($file));
            if (is_array($rec)) {
                $ts = (string) ($rec['meta']['updated_at'] ?? $rec['last_seen'] ?? '');
                if ($ts !== '') {
                    return $this->epoch($ts);
                }
            }
        } catch (Throwable) {
            // 坏文件按最旧处理
        }
        $mt = @filemtime($file);

        return $mt === false ? 0.0 : (float) $mt;
    }

    private function epoch(string $iso): float
    {
        if ($iso === '') {
            return 0.0;
        }
        try {
            return (float) (new \DateTimeImmutable($iso))->format('U.u');
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function ensureDirWithGitignore(string $dir): void
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $gitignore = $dir . '/.gitignore';
        if (! is_file($gitignore)) {
            @file_put_contents($gitignore, "*\n");
        }
    }

    /**
     * 清空后的旧目录(含空 bucket 子目录)能删则删;删不掉不报错。
     * git-sync 时代残留的 .gitkeep / .gitignore 一并清(只认这两个名字,不碰其他文件)。
     */
    private function removeDirIfEmpty(string $dir): void
    {
        foreach (['open', 'resolved', 'deleted', ''] as $bucket) {
            $d = $bucket === '' ? $dir : $dir . '/' . $bucket;
            @unlink($d . '/.gitkeep');
            @unlink($d . '/.gitignore');
            @rmdir($d);
        }
    }

    private function readJson(string $file): array
    {
        $json = json_decode((string) @file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    private function shortPath(string $abs): string
    {
        $base = rtrim(base_path(), '/');
        if (str_starts_with($abs, $base)) {
            return ltrim(substr($abs, strlen($base)), '/');
        }

        return $abs;
    }
}
