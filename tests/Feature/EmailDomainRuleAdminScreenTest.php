<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EmailDomainRoleRule;
use App\Models\Role;
use App\Orchid\Screens\Role\RoleEditScreen;
use App\Orchid\Screens\System\EmailDomainRuleEditScreen;
use App\Orchid\Screens\System\EmailDomainRuleListScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The admin screens behind the domain rules and the role flag.
 */
class EmailDomainRuleAdminScreenTest extends TestCase
{
    use RefreshDatabase;

    private function makeRole(string $slug = 'student'): Role
    {
        return Role::create([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'permissions' => [],
            'selfassign' => true,
            'require_email_verification' => false,
        ]);
    }

    public function test_a_rule_is_saved_normalised(): void
    {
        $role = $this->makeRole();

        $request = Request::create('/', 'POST', [
            'rule' => [
                'pattern' => '  STUD.Example.ORG ',
                'role_id' => $role->id,
                'priority' => 5,
                'is_active' => '1',
                'description' => 'Students',
            ],
        ]);

        (new EmailDomainRuleEditScreen)->save($request);

        $rule = EmailDomainRoleRule::firstOrFail();
        $this->assertSame('stud.example.org', $rule->pattern);
        $this->assertSame($role->id, $rule->role_id);
        $this->assertSame(5, $rule->priority);
        $this->assertTrue($rule->is_active);
    }

    public function test_an_invalid_pattern_is_rejected(): void
    {
        $role = $this->makeRole();

        $request = Request::create('/', 'POST', [
            'rule' => [
                'pattern' => 'not a host',
                'role_id' => $role->id,
                'priority' => 0,
            ],
        ]);

        $this->expectException(ValidationException::class);

        (new EmailDomainRuleEditScreen)->save($request);
    }

    public function test_a_rule_can_be_deactivated_from_the_list(): void
    {
        $role = $this->makeRole();
        $rule = EmailDomainRoleRule::create([
            'pattern' => 'example.org',
            'role_id' => $role->id,
            'priority' => 0,
            'is_active' => true,
        ]);

        (new EmailDomainRuleListScreen)->toggleActive(Request::create('/', 'POST', ['id' => $rule->id]));

        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_the_role_screen_stores_the_verification_flag(): void
    {
        $role = $this->makeRole();

        $request = Request::create('/', 'POST', [
            'role' => [
                'name' => 'Student',
                'slug' => 'student',
                'selfassign' => '1',
                'require_email_verification' => '1',
            ],
            'permissions' => [],
        ]);

        (new RoleEditScreen)->save($request, $role);

        $this->assertTrue((bool) $role->fresh()->require_email_verification);
    }
}
