<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\EmailDomainRoleRule;

/**
 * Resolves the role a self-registering user receives from the domain part of their e-mail address.
 *
 * Domain filtering is active as soon as one active rule exists. While it is active the user
 * cannot pick a role any more and an address matching no rule is rejected.
 */
class EmailDomainRoleResolver
{
    /**
     * Whether domain filtering takes over the role assignment.
     */
    public function isActive(): bool
    {
        return EmailDomainRoleRule::where('is_active', true)->exists();
    }

    /**
     * The first active rule matching the address, by priority.
     */
    public function match(string $email): ?EmailDomainRoleRule
    {
        $host = $this->hostOf($email);

        if ($host === null) {
            return null;
        }

        foreach (EmailDomainRoleRule::activeByPriority()->with('role')->get() as $rule) {
            if ($this->matchesPattern($host, $rule->pattern)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The host part of an address, normalised for comparison.
     */
    public function hostOf(string $email): ?string
    {
        $position = strrpos($email, '@');

        if ($position === false) {
            return null;
        }

        $host = strtolower(trim(substr($email, $position + 1)));
        $host = rtrim($host, '.');

        return $host === '' ? null : $host;
    }

    /**
     * An exact host, or a wildcard matching subdomains of the host.
     */
    private function matchesPattern(string $host, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));

        if (str_starts_with($pattern, '*.')) {
            $base = substr($pattern, 2);

            // The wildcard covers subdomains only, an address at the bare host needs its own rule.
            return $base !== '' && str_ends_with($host, '.'.$base);
        }

        return $host === $pattern;
    }
}
