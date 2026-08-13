<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LDAP_DEFAULT_KEY = 'ldap_connections.default.employee_type_default';

    private const LDAP_ATTRIBUTE_KEY = 'ldap_connections.default.attribute_map.employeeType';

    private const OIDC_DEFAULT_KEY = 'open_id_connect_employeetype_default';

    /**
     * Both separators are in use for OIDC attribute map settings, see AuthMethodEditScreen::buildOidcSettings().
     */
    private const OIDC_ATTRIBUTE_KEYS = [
        'open_id_connect_attribute_map.employeetype',
        'open_id_connect_attribute_map__employeetype',
    ];

    /**
     * Run the migrations.
     *
     * Adds the fallback employee type settings for LDAP and OIDC to the admin panel.
     *
     * Until now a user whose directory entry / user info carried no employee type attribute was
     * rejected during login, even though the authentication itself had already succeeded. The
     * fallback makes such logins work; an empty value restores the old reject-the-login behavior.
     */
    public function up(): void
    {
        $this->addSetting(
            self::LDAP_DEFAULT_KEY,
            config('ldap.connections.default.employee_type_default', 'guest'),
            'ldap',
            'EmployeeType used when the LDAP entry has none (empty rejects the login)'
        );

        $this->addSetting(
            self::OIDC_DEFAULT_KEY,
            config('open_id_connect.employeetype_default', 'guest'),
            'open_id_connect',
            'Employeetype used when the provider delivers none (empty rejects the login)'
        );

        // Both attributes now accept a comma separated list of attribute names.
        DB::table('app_settings')
            ->whereIn('key', [self::LDAP_ATTRIBUTE_KEY, ...self::OIDC_ATTRIBUTE_KEYS])
            ->update(['description' => 'Employeetype Key Name Override (comma separated list allowed, first match wins)']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('app_settings')
            ->whereIn('key', [self::LDAP_DEFAULT_KEY, self::OIDC_DEFAULT_KEY])
            ->delete();

        DB::table('app_settings')
            ->whereIn('key', [self::LDAP_ATTRIBUTE_KEY, ...self::OIDC_ATTRIBUTE_KEYS])
            ->update(['description' => 'Employeetype Key Name Override']);
    }

    private function addSetting(string $key, ?string $value, string $source, string $description): void
    {
        if (DB::table('app_settings')->where('key', $key)->exists()) {
            return;
        }

        DB::table('app_settings')->insert([
            'key' => $key,
            'value' => $value,
            'source' => $source,
            'group' => 'authentication',
            'type' => 'string',
            'description' => $description,
            'is_private' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
