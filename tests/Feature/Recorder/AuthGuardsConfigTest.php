<?php

declare(strict_types=1);

namespace Mooeen\Monitor\Tests\Feature\Recorder;

use Illuminate\Http\Request;
use Mooeen\Monitor\Recorder\RuntimeErrorRecorder;
use Mooeen\Monitor\Tests\TestCase;
use RuntimeException;

/**
 * P2-5 回归锁：auth guard 硬编码 → 配置化（覆盖面）。
 *
 * 默认只试 admin/user/web；宿主用 api/sanctum/自定义 guard 时，配 auth_guards 才能采到出错用户。
 */
class AuthGuardsConfigTest extends TestCase
{
    /** 伪造一个 auth 管理器：只有名为 $userGuard 的 guard 有登录用户。 */
    private function fakeAuthWithUserOn(string $userGuard): void
    {
        $user = new class
        {
            public $name = 'Alice';

            public function getKey(): int
            {
                return 42;
            }
        };

        // 必须 implements Auth\Factory：auth() helper 的返回类型是 Factory|Guard，否则 TypeError 被吞、采不到。
        $this->app->instance('auth', new class($userGuard, $user) implements \Illuminate\Contracts\Auth\Factory
        {
            public function __construct(private string $userGuard, private object $user) {}

            public function guard($name = null)
            {
                $u = $name === $this->userGuard ? $this->user : null;

                return new class($u)
                {
                    public function __construct(private ?object $u) {}

                    public function user(): ?object
                    {
                        return $this->u;
                    }
                };
            }

            public function shouldUse($name): void {}
        });
    }

    private function rm(string $base): void
    {
        foreach (glob($base . '/*/*.yaml') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            @rmdir($d);
        }
        @unlink($base . '/.gitignore');
        @rmdir($base);
    }

    public function test_custom_guard_user_captured_only_when_configured(): void
    {
        $this->fakeAuthWithUserOn('api');
        $request = Request::create('http://localhost/api/x', 'GET');

        // 默认 guards（admin/user/web）不含 api → 采不到用户
        $baseA = sys_get_temp_dir() . '/rt_guard_a_' . uniqid();
        $recA  = new RuntimeErrorRecorder($baseA, ['enabled' => true]);
        try {
            $reqData = $recA->get($recA->record(new RuntimeException('no guard'), $request))['request'];
            expect($reqData['user_id'])->toBeNull();
            expect($reqData['guard'])->toBeNull();
        } finally {
            $this->rm($baseA);
        }

        // 配置 auth_guards=['api'] → 采到用户
        $baseB = sys_get_temp_dir() . '/rt_guard_b_' . uniqid();
        $recB  = new RuntimeErrorRecorder($baseB, ['enabled' => true, 'auth_guards' => ['api']]);
        try {
            $reqData = $recB->get($recB->record(new RuntimeException('with guard'), $request))['request'];
            expect($reqData['user_id'])->toBe('42');
            expect($reqData['user_name'])->toBe('Alice');
            expect($reqData['guard'])->toBe('api');
        } finally {
            $this->rm($baseB);
        }
    }
}
