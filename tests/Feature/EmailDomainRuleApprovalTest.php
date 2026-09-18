<?php

namespace Tests\Feature;

use App\Models\AppLocalizedText;
use App\Models\EmailDomainRoleRule;
use App\Models\Employeetype;
use App\Models\EmployeetypeRole;
use App\Models\MailTemplate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A domain rule carries the approval decision for the accounts it matches, and saving
 * one keeps the employeetype role assignment in step with it.
 */
class EmailDomainRuleApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.local_authentication', true);
        Config::set('auth.local_selfservice', true);
        // The installation as a whole asks for approval; a rule may waive it.
        Config::set('auth.local_needapproval', true);
        Config::set('auth.authentication_method', 'LOCAL_ONLY');
        Config::set('hawki.send_registration_mails', true);

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

    private function makeRule(Role $role, bool $needsApproval): EmailDomainRoleRule
    {
        return EmailDomainRoleRule::create([
            'pattern' => 'stud.example.org',
            'role_id' => $role->id,
            'needs_admin_approval' => $needsApproval,
            'priority' => 0,
            'is_active' => true,
        ]);
    }

    private function submit(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/req/submit-guest-request', [
            'username' => 'guest-user',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'email' => 'guest@stud.example.org',
        ]);
    }

    private function lastMailedCode(): ?string
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        if ($messages->isEmpty()) {
            return null;
        }

        $body = $messages->last()->getOriginalMessage()->toString();

        return preg_match('/\b(\d{6})\b/', $body, $matches) ? $matches[1] : null;
    }

    private function registerAndConfirm(): User
    {
        $token = $this->submit()->json('token');

        $this->postJson('/req/submit-guest-request/verify', [
            'token' => $token,
            'otp' => $this->lastMailedCode(),
        ])->assertOk();

        return User::where('username', 'guest-user')->firstOrFail();
    }

    public function test_saving_a_rule_creates_the_linked_role_assignment(): void
    {
        $role = $this->makeRole('selfservice');

        $rule = $this->makeRule($role, false);

        $employeetype = Employeetype::where('raw_value', 'selfservice')
            ->where('auth_method', 'local')
            ->first();

        $this->assertNotNull($employeetype, 'The rule should create the employeetype it assigns.');
        $this->assertSame($role->name, $employeetype->display_name);
        $this->assertTrue($employeetype->is_active);

        $assignment = EmployeetypeRole::where('employeetype_id', $employeetype->id)
            ->where('role_id', $role->id)
            ->first();

        $this->assertNotNull($assignment, 'The rule should create the primary role assignment.');
        $this->assertTrue($assignment->is_primary);
        $this->assertSame($role->slug, $rule->role->slug);
    }

    public function test_pointing_a_rule_at_another_role_adds_that_assignment_too(): void
    {
        $student = $this->makeRole('student');
        $staff = $this->makeRole('staff');

        $rule = $this->makeRule($student, false);
        $rule->update(['role_id' => $staff->id]);

        foreach (['student' => $student, 'staff' => $staff] as $slug => $role) {
            $employeetype = Employeetype::where('raw_value', $slug)->where('auth_method', 'local')->first();

            $this->assertNotNull($employeetype, "The employeetype for {$slug} should exist.");
            $this->assertDatabaseHas('employeetype_roles', [
                'employeetype_id' => $employeetype->id,
                'role_id' => $role->id,
                'is_primary' => true,
            ]);
        }
    }

    public function test_a_rule_without_admin_approval_grants_the_role_once_the_address_is_confirmed(): void
    {
        $role = $this->makeRole('selfservice');
        $this->makeRule($role, false);

        $user = $this->registerAndConfirm();

        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue((bool) $user->approval, 'The rule waives the approval step.');
        $this->assertSame('selfservice', $user->roles()->first()?->slug);
    }

    public function test_a_rule_with_admin_approval_leaves_the_account_waiting_without_a_role(): void
    {
        $role = $this->makeRole('selfservice');
        $this->makeRule($role, true);

        $user = $this->registerAndConfirm();

        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse((bool) $user->approval, 'The rule keeps the approval step.');
        $this->assertSame('selfservice', $user->employeetype);
        $this->assertCount(0, $user->roles, 'No role before an administrator releases the account.');
    }

    public function test_the_role_arrives_when_the_admin_approves_afterwards(): void
    {
        $role = $this->makeRole('selfservice');
        $this->makeRule($role, true);

        $user = $this->registerAndConfirm();
        $this->assertCount(0, $user->roles);

        $user->update(['approval' => true]);

        $this->assertSame('selfservice', $user->fresh()->roles()->first()?->slug);
    }

    public function test_an_unconfirmed_address_never_gets_the_role_even_without_approval(): void
    {
        $role = $this->makeRole('selfservice');
        $this->makeRule($role, false);

        $this->submit()->assertOk();

        $user = User::where('username', 'guest-user')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertTrue((bool) $user->approval);
        $this->assertCount(0, $user->roles, 'Approval alone is not enough, the address has to be confirmed.');
    }
}
