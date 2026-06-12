<?php declare(strict_types=1);

use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Recorder\SqlSlowRecorder;
use Symfony\Component\Yaml\Yaml;

/**
 * 红队对抗审计修复的回归锁:
 *   - whereIn 占位符列表归一(否则一条热查询裂成几十 hash、占满配额)
 *   - message 里可变 UUID 归一(否则同一异常裂 hash)
 *   - sql_last / exc_message 的值侧脱敏(URL 脱敏覆盖不到的密钥沉淀点)
 */
function rhm_rm(string $base): void
{
    foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    @rmdir($base);
}

/** 同一行创建异常,保证 file:line 一致 —— 这样 hash 只取决于 normalizeMessage。 */
function rhm_exc(string $msg): RuntimeException
{
    return new RuntimeException($msg);
}

it('SqlSlow: whereIn 不同占位符个数 → 同一 hash(count 聚合)', function () {
    $base = sys_get_temp_dir() . '/rhm_' . uniqid();
    $rec  = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0, 'mask_keys' => ['token']]);

    try {
        $h1 = $rec->record('select * from users where id in (?, ?, ?)', 'select * from users where id in (1, 2, 3)', 200.0, '/app/F.php', 10);
        $h2 = $rec->record('select * from users where id in (?, ?, ?, ?, ?)', 'select * from users where id in (1,2,3,4,5)', 200.0, '/app/F.php', 10);

        expect($h1)->toBe($h2);
        $files = glob($base . '/open/*.yaml');
        expect($files)->toHaveCount(1)
            ->and((int) Yaml::parseFile($files[0])['count'])->toBe(2);
    } finally {
        rhm_rm($base);
    }
});

it('SqlSlow: sql_last 里敏感列的值被脱敏', function () {
    $base = sys_get_temp_dir() . '/rhm_' . uniqid();
    $rec  = new SqlSlowRecorder($base, ['enabled' => true, 'threshold_ms' => 0, 'mask_keys' => ['token', 'password']]);

    try {
        $rec->record('select * from t where api_token = ?', "select * from t where api_token = 'sk-live-SECRET'", 200.0, '/app/F.php', 10);
        $last = Yaml::parseFile(glob($base . '/open/*.yaml')[0])['sql']['last'];

        expect($last)->toContain('***')->not->toContain('sk-live-SECRET');
    } finally {
        rhm_rm($base);
    }
});

it('Runtime: message 里可变 UUID → 同一 hash(count 聚合)', function () {
    $base = sys_get_temp_dir() . '/rhm_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true, 'mask_keys' => ['token']]);

    try {
        $h1 = $rec->record(rhm_exc('No results for model [User] 550e8400-e29b-41d4-a716-446655440000'));
        $h2 = $rec->record(rhm_exc('No results for model [User] 6ba7b810-9dad-11d1-80b4-00c04fd430c8'));

        expect($h1)->toBe($h2);
        expect((int) Yaml::parseFile(glob($base . '/open/*.yaml')[0])['count'])->toBe(2);
    } finally {
        rhm_rm($base);
    }
});

it('Runtime: exc_message 里敏感列的值被脱敏', function () {
    $base = sys_get_temp_dir() . '/rhm_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true, 'mask_keys' => ['token']]);

    try {
        $rec->record(rhm_exc("SQLSTATE...: select * from t where token = 'sk-SECRET' and x=1"));
        $msg = Yaml::parseFile(glob($base . '/open/*.yaml')[0])['exception']['message'];

        expect($msg)->toContain('***')->not->toContain('sk-SECRET');
    } finally {
        rhm_rm($base);
    }
});

it('Runtime: exc_message 里的 JWT / Bearer 被脱敏(非 SQL 形态)', function () {
    $base = sys_get_temp_dir() . '/rhm_' . uniqid();
    $rec  = new RuntimeErrorRecorder($base, ['enabled' => true, 'mask_keys' => ['token', 'password']]);

    try {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJVadQssw5c';
        $rec->record(rhm_exc("auth failed, old_token: {$jwt} Bearer {$jwt}"));
        $msg = Yaml::parseFile(glob($base . '/open/*.yaml')[0])['exception']['message'];

        expect($msg)->toContain('***')->not->toContain('SflKxwRJSM');
    } finally {
        rhm_rm($base);
    }
});
