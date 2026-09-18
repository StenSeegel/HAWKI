<?php

namespace Tests\Feature;

use App\Models\AppLocalizedText;
use App\Models\EmailVerificationCode;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\Contract\AuthServiceInterface;
use App\Services\Auth\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * Self-registration with the 6-digit code every self-registering local user confirms.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.local_authentication', true);
        Config::set('auth.local_selfservice', true);
        Config::set('auth.local_needapproval', false);
        Config::set('auth.authentication_method', 'LOCAL_ONLY');
        Config::set('hawki.send_registration_mails', true);

        // No frontend build is required to render the gateway views here.
        $this->withoutVite();

        MailTemplate::create([
            'type' => 'otp',
            'language' => 'en',
            'description' => 'Authentication code email',
            'subject' => 'Your code',
            'body' => 'Your confirmation code is {{otp}}.',
        ]);

        // The gateway layout renders the settings panel, which expects this text to exist.
        AppLocalizedText::create([
            'content_key' => 'about_system',
            'language' => 'en_US',
            'content' => 'About HAWKI',
        ]);
    }

    private function makeRole(string $slug, bool $requireVerification): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'permissions' => [],
            'selfassign' => true,
            'require_email_verification' => $requireVerification,
        ]);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/req/submit-guest-request', array_merge([
            'username' => 'guest-user',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'email' => 'guest@example.org',
            'employeetype' => 'student',
        ], $overrides));
    }

    /**
     * The code only exists as a hash, so it has to be read back out of the mail that was sent.
     */
    private function lastMailedCode(): ?string
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        if ($messages->isEmpty()) {
            return null;
        }

        $body = $messages->last()->getOriginalMessage()->toString();

        return preg_match('/\b(\d{6})\b/', $body, $matches) ? $matches[1] : null;
    }

    public function test_submit_without_verification_creates_a_verified_account(): void
    {
        $this->makeRole('student', false);

        $response = $this->submit();

        $response->assertOk()
            ->assertJson(['success' => true, 'verification_required' => false]);

        $user = User::where('username', 'guest-user')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('student', $user->roles()->first()?->slug);
        $this->assertDatabaseCount('email_verification_codes', 0);
        $this->assertTrue(Mail::mailer()->getSymfonyTransport()->messages()->isEmpty());
    }

    public function test_role_flag_creates_an_unverified_account_without_a_role_and_mails_a_code(): void
    {
        $this->makeRole('student', true);

        $response = $this->submit();

        $response->assertOk()
            ->assertJson(['success' => true, 'verification_required' => true]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertStringContainsString('@example.org', (string) $response->json('email_masked'));
        $this->assertStringNotContainsString('guest@', (string) $response->json('email_masked'));

        $user = User::where('username', 'guest-user')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->roles()->count());
        $this->assertDatabaseCount('email_verification_codes', 1);

        $code = $this->lastMailedCode();
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_correct_code_verifies_the_account_and_attaches_the_role(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');
        $code = $this->lastMailedCode();

        $response = $this->postJson('/req/submit-guest-request/verify', [
            'token' => $token,
            'otp' => $code,
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        $user = User::where('username', 'guest-user')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('student', $user->roles()->first()?->slug);
        $this->assertDatabaseCount('email_verification_codes', 0);

        // Only the code itself was mailed, confirming it does not trigger another mail.
        $this->assertCount(1, Mail::mailer()->getSymfonyTransport()->messages());
    }

    public function test_wrong_codes_count_down_and_the_third_one_locks_the_step(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');
        $mailed = $this->lastMailedCode();

        $first = $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => '000000']);
        $first->assertStatus(422)->assertJson(['reason' => 'otp_invalid', 'attempts_left' => 2]);

        $second = $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => '000001']);
        $second->assertStatus(422)->assertJson(['reason' => 'otp_invalid', 'attempts_left' => 1]);

        $third = $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => '000002']);
        $third->assertStatus(422)->assertJson(['reason' => 'otp_locked', 'attempts_left' => 0]);
        $this->assertGreaterThan(0, $third->json('locked_for_minutes'));

        // The row stays, because the lock has to outlive the code it belonged to.
        $this->assertDatabaseCount('email_verification_codes', 1);

        // The code that was mailed is worthless from here on.
        $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => $mailed])
            ->assertStatus(422)
            ->assertJson(['reason' => 'otp_locked']);
    }

    public function test_a_locked_step_hands_out_no_new_code(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');

        foreach (['000000', '000001', '000002'] as $attempt) {
            $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => $attempt]);
        }

        $mailsBefore = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->postJson('/req/submit-guest-request/resend', ['token' => $token])
            ->assertStatus(422)
            ->assertJson(['reason' => 'otp_locked']);

        // Changing the address must not be a way around the lock either.
        $this->postJson('/req/submit-guest-request/change-address', [
            'token' => $token,
            'email' => 'somewhere-else@example.org',
        ])->assertStatus(422)->assertJson(['reason' => 'otp_locked']);

        $this->assertSame(
            $mailsBefore,
            Mail::mailer()->getSymfonyTransport()->messages()->count(),
            'No further code may be sent while the step is locked.'
        );
    }

    public function test_the_step_opens_again_once_the_lock_has_passed(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');

        foreach (['000000', '000001', '000002'] as $attempt) {
            $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => $attempt]);
        }

        EmailVerificationCode::query()->update(['locked_until' => now()->subMinute()]);

        $this->postJson('/req/submit-guest-request/resend', ['token' => $token])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->postJson('/req/submit-guest-request/verify', [
            'token' => $token,
            'otp' => $this->lastMailedCode(),
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');
        $code = $this->lastMailedCode();

        EmailVerificationCode::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => $code])
            ->assertStatus(422)
            ->assertJson(['reason' => 'otp_expired']);

        $this->assertNull(User::where('username', 'guest-user')->first()?->email_verified_at);
    }

    public function test_resend_invalidates_the_previous_code(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');
        $firstCode = $this->lastMailedCode();

        $resend = $this->postJson('/req/submit-guest-request/resend', ['token' => $token]);
        $resend->assertOk()->assertJson(['success' => true]);

        $secondCode = $this->lastMailedCode();
        $this->assertNotSame($firstCode, $secondCode);

        $this->postJson('/req/submit-guest-request/verify', ['token' => $token, 'otp' => $firstCode])
            ->assertStatus(422)
            ->assertJson(['reason' => 'otp_invalid']);

        $this->postJson('/req/submit-guest-request/verify', ['token' => $resend->json('token'), 'otp' => $secondCode])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_change_address_revalidates_uniqueness_and_issues_a_new_code(): void
    {
        $this->makeRole('student', true);
        $token = $this->submit()->json('token');

        User::create([
            'username' => 'taken-user',
            'name' => 'Taken',
            'email' => 'taken@example.org',
            'password' => 'Password123',
            'employeetype' => 'student',
            'auth_type' => 'local',
            'publicKey' => '',
            'approval' => true,
            'isRemoved' => false,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/req/submit-guest-request/change-address', [
            'token' => $token,
            'email' => 'taken@example.org',
        ])->assertStatus(422)->assertJson(['reason' => 'email_taken']);

        $changed = $this->postJson('/req/submit-guest-request/change-address', [
            'token' => $token,
            'email' => 'corrected@example.org',
        ]);

        $changed->assertOk()->assertJson(['success' => true]);
        $this->assertSame('corrected@example.org', User::where('username', 'guest-user')->firstOrFail()->email);

        $this->postJson('/req/submit-guest-request/verify', [
            'token' => $changed->json('token'),
            'otp' => $this->lastMailedCode(),
        ])->assertOk();
    }

    public function test_verified_duplicate_is_rejected_and_unverified_duplicate_offers_a_resend(): void
    {
        $this->makeRole('student', true);
        $this->submit();

        $this->assertSame(1, User::where('email', 'guest@example.org')->count());

        $duplicate = $this->submit(['username' => 'second-user']);
        $duplicate->assertOk()->assertJson(['success' => false, 'reason' => 'unverified_exists']);
        $this->assertNotEmpty($duplicate->json('token'));
        $this->assertSame(1, User::where('email', 'guest@example.org')->count());

        // Once the address is confirmed, the ordinary uniqueness message comes back.
        User::where('username', 'guest-user')->update(['email_verified_at' => now()]);

        $this->submit(['username' => 'third-user'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_unverified_user_logging_in_lands_on_the_pre_slide_and_cannot_complete_registration(): void
    {
        $this->makeRole('student', true);
        $this->submit();
        $this->app->forgetInstance(AuthServiceInterface::class);

        $login = $this->postJson('/req/login', [
            'account' => 'guest-user',
            'password' => 'Password123',
        ]);

        $login->assertOk()->assertJson(['success' => true, 'redirectUri' => '/register']);
        $this->assertTrue(Session::get('needs_email_verification'));

        $this->get('/register')
            ->assertOk()
            ->assertSee('verify-email-slide');

        $mailsBefore = Mail::mailer()->getSymfonyTransport()->messages()->count();

        $this->postJson('/req/complete_registration', [
            'publicKey' => 'public-key',
            'keychain' => 'keychain',
            'KCIV' => 'iv',
            'KCTAG' => 'tag',
        ])->assertStatus(403)->assertJson(['reason' => 'email_not_verified']);

        // No approval-pending or welcome mail is sent for an account that never confirmed.
        $this->assertSame($mailsBefore, Mail::mailer()->getSymfonyTransport()->messages()->count());
        $this->assertSame('', User::where('username', 'guest-user')->firstOrFail()->publicKey);
    }

    public function test_the_authenticated_twin_routes_verify_the_session_user(): void
    {
        $this->makeRole('student', true);
        $this->submit();
        $code = $this->lastMailedCode();

        Session::put('registration_access', true);
        Session::put('authenticatedUserInfo', json_encode([
            'username' => 'guest-user',
            'name' => 'guest-user',
            'email' => 'guest@example.org',
            'employeetype' => 'student',
        ]));

        $this->postJson('/req/verify-email/verify', ['otp' => $code])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNotNull(User::where('username', 'guest-user')->firstOrFail()->email_verified_at);
    }

    public function test_verification_is_not_required_for_admin_created_local_users(): void
    {
        $this->makeRole('student', true);

        $user = User::create([
            'username' => 'admin-made',
            'name' => 'Admin Made',
            'email' => 'admin-made@example.org',
            'password' => 'Password123',
            'employeetype' => 'student',
            'auth_type' => 'local',
            'publicKey' => '',
            'approval' => true,
            'isRemoved' => false,
            'email_verified_at' => now(),
        ]);

        $this->assertFalse(app(EmailVerificationService::class)->needsVerification($user));
        $this->assertSame('student', $user->roles()->first()?->slug);
    }
}
