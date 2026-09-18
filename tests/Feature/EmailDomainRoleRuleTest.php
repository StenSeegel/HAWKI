<?php

namespace Tests\Feature;

use App\Models\AppLocalizedText;
use App\Models\EmailDomainRoleRule;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\EmailDomainRoleResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Domain filtering: with at least one active rule the role comes from the address,
 * and every registration has to be verified.
 */
class EmailDomainRoleRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.local_authentication', true);
        Config::set('auth.local_selfservice', true);
        Config::set('auth.local_needapproval', false);
        Config::set('auth.authentication_method', 'LOCAL_ONLY');

        $this->withoutVite();

        MailTemplate::create([
            'type' => 'otp',
            'language' => 'en',
            'description' => 'Authentication code email',
            'subject' => 'Your code',
            'body' => 'Your confirmation code is {{otp}}.',
        ]);

        AppLocalizedText::create([
            'content_key' => 'about_system',
            'language' => 'en_US',
            'content' => 'About HAWKI',
        ]);
    }

    private function makeRole(string $slug): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'permissions' => [],
            'selfassign' => true,
            'require_email_verification' => false,
        ]);
    }

    private function makeRule(string $pattern, Role $role, int $priority = 0, bool $active = true): EmailDomainRoleRule
    {
        return EmailDomainRoleRule::create([
            'pattern' => $pattern,
            'role_id' => $role->id,
            'priority' => $priority,
            'is_active' => $active,
        ]);
    }

    private function submit(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/req/submit-guest-request', array_merge([
            'username' => 'guest-user',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'email' => 'guest@stud.example.org',
        ], $overrides));
    }

    public function test_resolver_matches_exact_hosts_wildcards_and_ignores_case(): void
    {
        $student = $this->makeRole('student');
        $staff = $this->makeRole('staff');

        $this->makeRule('stud.example.org', $student, 10);
        $this->makeRule('*.example.org', $staff, 20);

        $resolver = app(EmailDomainRoleResolver::class);

        $this->assertTrue($resolver->isActive());
        $this->assertSame($student->id, $resolver->match('a@stud.example.org')?->role_id);
        $this->assertSame($student->id, $resolver->match('A@STUD.Example.ORG')?->role_id);
        $this->assertSame($staff->id, $resolver->match('a@mail.example.org')?->role_id);
        $this->assertNull($resolver->match('a@example.org'));
        $this->assertNull($resolver->match('a@other.tld'));
    }

    public function test_priority_decides_and_inactive_rules_are_ignored(): void
    {
        $student = $this->makeRole('student');
        $staff = $this->makeRole('staff');

        $this->makeRule('*.example.org', $staff, 5);
        $this->makeRule('stud.example.org', $student, 1);

        $resolver = app(EmailDomainRoleResolver::class);
        $this->assertSame($student->id, $resolver->match('a@stud.example.org')?->role_id);

        EmailDomainRoleRule::where('pattern', 'stud.example.org')->update(['is_active' => false]);
        $this->assertSame($staff->id, $resolver->match('a@stud.example.org')?->role_id);

        EmailDomainRoleRule::query()->update(['is_active' => false]);
        $this->assertFalse($resolver->isActive());
    }

    public function test_the_user_group_dropdown_disappears_while_filtering_is_active(): void
    {
        $student = $this->makeRole('student');

        $this->get('/login')->assertOk()->assertSee('request-employeetype');

        $this->makeRule('stud.example.org', $student);

        $this->get('/login')->assertOk()->assertDontSee('request-employeetype');
    }

    public function test_a_matching_address_gets_the_rule_role_and_has_to_verify(): void
    {
        $student = $this->makeRole('student');
        $this->makeRole('staff');
        $rule = $this->makeRule('stud.example.org', $student);

        // The role flag is off, domain filtering alone makes verification mandatory.
        $response = $this->submit(['employeetype' => 'staff']);

        $response->assertOk()->assertJson(['success' => true, 'verification_required' => true]);

        $user = User::where('username', 'guest-user')->firstOrFail();
        $this->assertSame('student', $user->employeetype);
        $this->assertSame($rule->id, $user->domain_rule_id);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(0, $user->roles()->count());
    }

    public function test_a_non_matching_address_is_rejected_before_any_row_or_mail(): void
    {
        $student = $this->makeRole('student');
        $this->makeRule('stud.example.org', $student);

        $this->submit(['email' => 'guest@other.tld'])
            ->assertStatus(422)
            ->assertJson(['reason' => 'domain_not_allowed']);

        $this->assertDatabaseMissing('users', ['username' => 'guest-user']);
        $this->assertTrue(Mail::mailer()->getSymfonyTransport()->messages()->isEmpty());
    }

    public function test_changing_the_address_to_another_domain_switches_the_role(): void
    {
        $student = $this->makeRole('student');
        $staff = $this->makeRole('staff');
        $this->makeRule('stud.example.org', $student, 1);
        $staffRule = $this->makeRule('staff.example.org', $staff, 2);

        $token = $this->submit()->json('token');

        $this->postJson('/req/submit-guest-request/change-address', [
            'token' => $token,
            'email' => 'guest@nowhere.tld',
        ])->assertStatus(422)->assertJson(['reason' => 'domain_not_allowed']);

        $this->postJson('/req/submit-guest-request/change-address', [
            'token' => $token,
            'email' => 'guest@staff.example.org',
        ])->assertOk();

        $user = User::where('username', 'guest-user')->firstOrFail();
        $this->assertSame('staff', $user->employeetype);
        $this->assertSame($staffRule->id, $user->domain_rule_id);
    }
}
