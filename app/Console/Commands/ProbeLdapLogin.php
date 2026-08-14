<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\Value\AuthenticatedUserInfo;
use App\Services\Auth\Value\Ldap\LdapAttributeReader;
use App\Services\Auth\Value\Ldap\LdapBindCredentials;
use App\Services\Auth\Value\Ldap\LdapConnectUri;
use App\Services\Auth\Value\Ldap\LdapFilterArgs;
use Illuminate\Console\Command;

/**
 * Replays a login against the live directory and the live configuration, without a password and
 * without writing anything, to show which step rejects an account.
 *
 * LdapService throws on the first failure and reports one generic message, so a single missing
 * attribute is indistinguishable from a wrong password. This runs the same steps in the same order
 * but keeps going, so every failure is visible at once instead of only the first.
 */
class ProbeLdapLogin extends Command
{
    protected $signature = 'hawki:probe-ldap-login {username : The username as typed on the login form}';

    protected $description = 'Replay the LDAP login for a user, read-only, to find which step rejects them';

    public function handle(): int
    {
        if (! function_exists('ldap_connect')) {
            $this->error('The PHP LDAP extension is not loaded.');

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');
        $connectionName = config('ldap.default');
        $connection = config('ldap.connections.'.$connectionName);

        if (! is_array($connection)) {
            $this->error("LDAP connection '{$connectionName}' is not configured.");

            return self::FAILURE;
        }

        $map = $connection['attribute_map'];

        $this->line('<comment>Effective configuration</comment>');
        $this->line('  connection        '.$connectionName);
        $this->line('  username          '.$map['username']);
        $this->line('  email             '.$map['email']);
        $this->line('  name              '.$map['name']);
        $this->line('  employeeType      '.$map['employeeType']);
        $this->line('  default           '.var_export($connection['employee_type_default'] ?? '', true));
        $this->line('  invert_name       '.var_export($connection['invert_name'] ?? false, true));
        $this->newLine();

        try {
            $reader = new LdapAttributeReader(
                usernameAttribute: $map['username'],
                emailAttribute: $map['email'],
                displayNameAttribute: $map['name'],
                employeeTypeAttribute: $map['employeeType'],
                legacyInvertDisplayNameOrder: $connection['invert_name'] ?? false,
                employeeTypeDefault: $connection['employee_type_default'] ?? '',
                logger: null,
            );
            $uri = new LdapConnectUri($connection['ldap_host'], $connection['ldap_port']);
            $bind = new LdapBindCredentials($connection['ldap_bind_dn'], $connection['ldap_bind_pw']);
            $filter = new LdapFilterArgs($connection['ldap_base_dn'], $connection['ldap_filter']);
        } catch (\Throwable $e) {
            $this->error('Configuration rejected: '.$e->getMessage());

            return self::FAILURE;
        }

        $ldap = @ldap_connect((string) $uri);
        if (! $ldap) {
            $this->error("Cannot connect to {$uri}");

            return self::FAILURE;
        }

        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        if (! $bind->isAnonymousBind && ! @ldap_bind($ldap, $bind->bindDn, $bind->bindPw)) {
            $this->error('Service bind failed: '.ldap_error($ldap));

            return self::FAILURE;
        }

        $search = @ldap_search($ldap, $filter->baseDn, $filter->getFilterForUser($username));
        if ($search === false) {
            $this->error('Search failed: '.ldap_error($ldap));

            return self::FAILURE;
        }

        $entries = @ldap_get_entries($ldap, $search);
        if (! is_array($entries) || ($entries['count'] ?? 0) === 0) {
            $this->error('No entry found for '.$username);

            return self::FAILURE;
        }

        $this->line('<comment>Directory entry</comment>');
        $this->line('  dn                '.($entries[0]['dn'] ?? '?'));
        $this->line('  attributes        '.implode(', ', array_filter(array_keys($entries[0]), 'is_string')));
        $this->newLine();

        // The password bind is the one step this cannot replay. It happens before the attributes are
        // read, so any account that reaches the steps below has already passed it.
        $this->line('<comment>Attribute resolution (the order LdapService evaluates them in)</comment>');

        $resolved = [];
        $failed = false;

        foreach (['username' => 'getUsername', 'displayName' => 'getDisplayName', 'email' => 'getEmail', 'employeeType' => 'getEmployeeType'] as $field => $method) {
            try {
                $resolved[$field] = $reader->$method($entries);
                $this->line(sprintf('  <info>ok</info>   %-13s %s', $field, var_export($resolved[$field], true)));
            } catch (\Throwable $e) {
                $failed = true;
                $this->line(sprintf('  <fg=red>FAIL</> %-13s %s', $field, $e->getMessage()));
            }
        }

        ldap_unbind($ldap);
        $this->newLine();

        if ($failed) {
            $this->error('Authentication would fail here, before any user lookup.');

            return self::FAILURE;
        }

        $userInfo = new AuthenticatedUserInfo(
            username: $resolved['username'],
            displayName: $resolved['displayName'],
            email: $resolved['email'],
            employeeType: $resolved['employeeType'],
        );

        $this->line('<comment>What AuthenticationController does next</comment>');

        $user = User::where('username', $userInfo->username)->where('isRemoved', 0)->first();

        if ($user) {
            $this->line('  found user id     '.$user->id);
            $this->line('  auth_type         '.var_export($user->auth_type, true));
            $this->line('  employeetype      '.var_export($user->employeetype, true));
            $this->line('  approval          '.var_export($user->approval, true));
            $this->line('  roles             '.($user->roles()->pluck('slug')->implode(', ') ?: '(none)'));
            $this->newLine();
            $this->info('=> would log in and redirect to /handshake');

            return self::SUCCESS;
        }

        $removed = User::where('username', $userInfo->username)->where('isRemoved', 1)->exists();
        $this->line('  no active user row'.($removed ? ' (one exists but is marked removed)' : ''));
        $this->newLine();
        $this->info('=> would redirect to /register');

        return self::SUCCESS;
    }
}
