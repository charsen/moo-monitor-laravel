<?php declare(strict_types=1);

use Mooeen\MonitorLaravel\Recorder\SensitiveMasker;

it('共享跨 SDK 假数据保持脱敏和诊断可读性', function () {
    $fixture = json_decode(file_get_contents(__DIR__ . '/../../Fixtures/cross-sdk-redaction.json'), true, 512, JSON_THROW_ON_ERROR);
    $masker  = new SensitiveMasker($fixture['keys']);
    foreach ($fixture['cases'] as $case) {
        $out = $masker->maskSecrets($case['input']);
        foreach ($case['forbidden'] as $secret) {
            expect($out)->not->toContain($secret);
        }
        expect($out)->toContain($case['keep']);
    }
});

/**
 * P4-2 单测：SensitiveMasker 从 trait 独立成 final class 后可纯单测（构造器注入 mask_keys，无容器/config 依赖）。
 * 四类脱敏：URL query / SQL 值侧 / INSERT 列 / JWT+Bearer。
 */
it('maskUrl 只脱敏命中 mask_keys 的 query 参数', function () {
    $m = new SensitiveMasker(['token', 'api_key']);
    expect($m->maskUrl('https://h/x?token=abc&api_key=zzz&page=1#frag'))
        ->toBe('https://h/x?token=***&api_key=***&page=1#frag');
    expect($m->maskUrl('https://h/x'))->toBe('https://h/x'); // 无 query 原样
});

it('maskSensitiveSql 脱敏敏感列的值侧字面量', function () {
    $m = new SensitiveMasker(['password', 'token']);
    expect($m->maskSensitiveSql("where token = 'sk-live-SECRET' and id = 5"))
        ->toContain('***')->not->toContain('SECRET');
    // INSERT 列侧脱敏
    expect($m->maskSensitiveSql("insert into users (name, password) values ('bob', 'p@ss')"))
        ->toContain('***')->not->toContain('p@ss');
});

it('maskSecrets 脱敏 JWT / Bearer / Authorization / key=value', function () {
    $m   = new SensitiveMasker(['secret']);
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJVadQssw5c';
    expect($m->maskSecrets("token {$jwt}"))->toContain('***JWT***')->not->toContain('SflKxwRJSM');
    expect($m->maskSecrets('auth Bearer abc123def'))->toContain('Bearer ***')->not->toContain('abc123def');
    expect($m->maskSecrets('my_secret=hunter2'))->toContain('***')->not->toContain('hunter2');
});

it('shouldMaskKey 子串 + 大小写不敏感；空 mask_keys 全不脱敏', function () {
    $m = new SensitiveMasker(['token']);
    expect($m->shouldMaskKey('access_token'))->toBeTrue();
    expect($m->shouldMaskKey('ACCESS_TOKEN'))->toBeTrue();
    expect($m->shouldMaskKey('page'))->toBeFalse();

    $empty = new SensitiveMasker([]);
    expect($empty->shouldMaskKey('token'))->toBeFalse();
    expect($empty->maskSensitiveSql("where token = 'x'"))->toBe("where token = 'x'"); // 无 keys → 原样
});

it('完整脱敏引号和转义引号中的多词凭据', function () {
    $m = new SensitiveMasker(['password', 'token', 'secret']);
    foreach ([
        'password="first second final-secret" keep=visible',
        "db_password='first second final-secret' keep=visible",
        '{"access_token":"first \\"second\\" final-secret"} keep=visible',
    ] as $input) {
        expect($m->maskSecrets($input))->not->toContain('final-secret')->toContain('keep=visible');
    }
    expect($m->maskSecrets('my_secret: "single-value", keep=visible'))
        ->toBe('my_secret: "***", keep=visible');
});
