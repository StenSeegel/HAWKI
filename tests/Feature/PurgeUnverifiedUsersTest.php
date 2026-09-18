<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PurgeUnverifiedUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('auth.local_verification_purge_days', 7);

        Role::create([
            'name' => 'Student',
            'slug' => 'student',
            'permissions' => [],
            'selfassign' => true,
            'require_email_verification' => true,
        ]);
    }

    private function makeUser(string $username, ?\DateTimeInterface $verifiedAt, int $ageInDays): User
    {
        $user = User::create([
            'username' => $username,
            'name' => $username,
            'email' => $username.'@example.org',
            'password' => 'Password123',
            'employeetype' => 'student',
            'auth_type' => 'local',
            'publicKey' => '',
            'approval' => true,
            'isRemoved' => false,
            'email_verified_at' => $verifiedAt,
        ]);

        $user->forceFill(['created_at' => now()->subDays($ageInDays)])->saveQuietly();

        return $user;
    }

    public function test_only_stale_unverified_accounts_are_deleted(): void
    {
        $stale = $this->makeUser('stale-user', null, 10);
        $fresh = $this->makeUser('fresh-user', null, 2);
        $verified = $this->makeUser('verified-user', now(), 30);

        $this->artisan('hawki:purge-unverified-users')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $stale->id]);
        $this->assertDatabaseHas('users', ['id' => $fresh->id]);
        $this->assertDatabaseHas('users', ['id' => $verified->id]);
    }

    public function test_purging_frees_the_username_and_the_address(): void
    {
        $this->makeUser('stale-user', null, 10);

        $this->artisan('hawki:purge-unverified-users --days=7')->assertSuccessful();

        Config::set('auth.local_authentication', true);
        Config::set('auth.local_selfservice', true);

        $this->postJson('/req/submit-guest-request', [
            'username' => 'stale-user',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'email' => 'stale-user@example.org',
            'employeetype' => 'student',
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_dry_run_keeps_everything(): void
    {
        $stale = $this->makeUser('stale-user', null, 10);

        $this->artisan('hawki:purge-unverified-users --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $stale->id]);
    }
}
