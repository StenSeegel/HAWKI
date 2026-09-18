<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Submit, verify, resend and change-address are rate limited per IP.
 */
class EmailVerificationThrottlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.local_authentication', true);
        Config::set('auth.local_selfservice', true);

        Role::create([
            'name' => 'Student',
            'slug' => 'student',
            'permissions' => [],
            'selfassign' => true,
            'require_email_verification' => true,
        ]);
    }

    public function test_submitting_guest_requests_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/req/submit-guest-request', [
                'username' => 'guest-'.$i,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
                'email' => 'guest-'.$i.'@example.org',
                'employeetype' => 'student',
            ]);

            $this->assertNotSame(429, $response->status());
        }

        $this->postJson('/req/submit-guest-request', [
            'username' => 'guest-6',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'email' => 'guest-6@example.org',
            'employeetype' => 'student',
        ])->assertStatus(429);
    }

    /**
     * @dataProvider verificationRoutes
     */
    public function test_verification_routes_are_throttled(string $route): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertNotSame(429, $this->postJson($route, ['token' => 'nope'])->status());
        }

        $this->postJson($route, ['token' => 'nope'])->assertStatus(429);
    }

    public static function verificationRoutes(): array
    {
        return [
            'verify' => ['/req/submit-guest-request/verify'],
            'resend' => ['/req/submit-guest-request/resend'],
            'change address' => ['/req/submit-guest-request/change-address'],
        ];
    }
}
