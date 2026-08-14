<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default LDAP Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the LDAP connections below you wish
    | to use as your default connection for all LDAP operations. Of
    | course you may add as many connections you'd like below.
    |
    */

    'default' => env('LDAP_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | LDAP Connections
    |--------------------------------------------------------------------------
    |
    | Below you may configure each LDAP connection your application requires
    | access to. Be sure to include a valid base DN - otherwise you may
    | not receive any results when performing LDAP search operations.
    |
    */

    'connections' => [
        'default' =>[
            'ldap_host' => env('LDAP_HOST'),
            'ldap_port' => env('LDAP_PORT'),
            'ldap_bind_dn' => (static function () {
                $bindDn = env('LDAP_BIND_DN');
                if (!empty($bindDn)) {
                    return $bindDn;
                }
                // Historically the BASE_DN was used as BIND_DN if BIND_DN was not set
                // We keep this behavior for backward compatibility
                $baseDn = env('LDAP_BASE_DN');
                if (!empty($baseDn)) {
                    return $baseDn;
                }

                return null;
            })(),
            'ldap_bind_pw' => env('LDAP_BIND_PW'),
            'ldap_base_dn' => (static function () {
                $searchDn = env('LDAP_SEARCH_DN');
                if (!empty($searchDn)) {
                    return $searchDn;
                }

                // If the LDAP_BIND_DN is set, we assume that LDAP_BASE_DN is now correctly pointing to the base
                $bindDn = env('LDAP_BIND_DN');
                if (!empty($bindDn)) {
                    $baseDn = env('LDAP_BASE_DN');
                    if (!empty($baseDn)) {
                        return $baseDn;
                    }
                }

                // If the LDAP_BIND_DN is NOT set, we assume that LDAP_BASE_DN is still the BIND_DN for backward compatibility
                return null;
            })(),
            'ldap_filter'=> env('LDAP_FILTER'),

            'attribute_map' => [
                // May be a comma separated list, e.g. "cn,uid"; the first one that has a value wins.
                // Needed where a population does not expose the primary attribute - external accounts
                // often carry the cn only inside their DN, never as a readable attribute.
                // CAUTION: this value is the primary key of an account in HAWKI. Changing which
                // attribute a population resolves to orphans the accounts already registered under
                // the old value, so put the attribute existing users resolve to first.
                'username' => env("LDAP_ATTR_USERNAME", "cn"),
                // May be a comma separated list; the first one that has a value wins.
                'email' => env("LDAP_ATTR_EMAIL", "mail"),
                // May be a comma separated list of attribute names; the first one that has a value wins.
                'employeeType' => env("LDAP_ATTR_EMPLOYEETYPE", "employeetype"),
                'name' => env("LDAP_ATTR_NAME", "displayname"),
            ],
            // Used when the entry carries none of the attributes above, so that such users can still
            // log in. Set to an empty string to reject those logins instead.
            'employee_type_default' => env('LDAP_ATTR_EMPLOYEETYPE_DEFAULT', 'guest'),
            'invert_name' => env('LDAP_INVERT_NAME', true),
        ],
    ],
];
