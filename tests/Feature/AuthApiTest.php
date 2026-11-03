<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_login_returns_a_token_and_logout_revokes_it(): void
    {
        $user = User::factory()->create([
            'name' => 'Dispatcher',
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ]);
        $user->roles()->attach(Role::query()->where('code', 'DISPATCH_OPERATOR')->value('id'));

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => ' DISPATCHER@example.com ',
            'password' => 'a-strong-test-password',
            'device_name' => 'control-room-test',
        ]);

        $login->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'token_type',
                    'user' => ['id', 'name', 'email', 'roles', 'permissions'],
                ],
            ])
            ->assertJsonPath('data.user.email', 'dispatcher@example.com');

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'dispatcher@example.com')
            ->assertJsonFragment(['jobs.assign']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.login',
        ]);

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'event' => 'auth.logout',
        ]);
        $this->assertSame(2, AuditLog::query()->where('user_id', $user->id)->count());
    }

    public function test_invalid_credentials_and_inactive_accounts_are_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dispatcher@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'dispatcher@example.com',
            'password' => 'a-strong-test-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_validates_inputs_and_protected_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => ['not', 'an', 'email'],
            'password' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_browser_requests_to_unauthenticated_api_routes_receive_json_401(): void
    {
        $this->get('/api/v1/auth/me', ['Accept' => 'text/html'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_api_requests_reject_and_revoke_tokens_for_deactivated_accounts(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-device')->plainTextToken;
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_throttle_limits_repeated_attempts_for_an_account(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'unknown@example.com',
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'unknown@example.com',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_login_throttle_limits_password_spraying_across_account_names(): void
    {
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'spray-'.$attempt.'@example.com',
                'password' => 'incorrect-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'email' => 'another-account@example.com',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_security_headers_are_added_and_hsts_is_limited_to_https(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }
}
