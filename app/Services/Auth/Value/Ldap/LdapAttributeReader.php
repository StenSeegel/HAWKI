<?php
declare(strict_types=1);


namespace App\Services\Auth\Value\Ldap;


use App\Services\Auth\Exception\LdapException;
use App\Services\Auth\Util\DisplayNameBuilder;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

readonly class LdapAttributeReader
{
    /**
     * True if the display name is built from multiple LDAP attributes
     * @var bool
     */
    private bool $displayNameAttributeIsArray;
    /**
     * True if we need to apply the legacy inversion of display name order (e.g., "Lastname, Firstname"), becoming "Firstname Lastname"
     * @var bool
     */
    private bool $legacyInvertDisplayNameOrder;
    /**
     * The employee type attribute definition split into single candidate attribute names.
     * The first candidate that carries a value on the LDAP entry wins.
     * @var string[]
     */
    private array $employeeTypeAttributes;
    public string $usernameAttribute;
    public string $emailAttribute;
    public string $displayNameAttribute;
    /**
     * The raw employee type attribute definition; may be a comma separated list of attribute names.
     */
    public string $employeeTypeAttribute;
    /**
     * Value used when none of the configured employee type attributes are present on the entry.
     * An empty string means "no default" and restores the legacy behavior of failing the login.
     */
    public string $employeeTypeDefault;

    public function __construct(
        mixed                    $usernameAttribute,
        mixed                    $emailAttribute,
        mixed                    $displayNameAttribute,
        mixed                    $employeeTypeAttribute,
        mixed                    $legacyInvertDisplayNameOrder,
        mixed                    $employeeTypeDefault = '',
        private ?LoggerInterface $logger = null
    )
    {
        if (!is_string($usernameAttribute) || empty($usernameAttribute)) {
            throw new LdapException('The LDAP "username" attribute must be a non-empty string.');
        }
        $this->usernameAttribute = $usernameAttribute;

        if (!is_string($emailAttribute) || empty($emailAttribute)) {
            throw new LdapException('The LDAP "email" attribute must be a non-empty string.');
        }
        $this->emailAttribute = $emailAttribute;

        if (!is_string($displayNameAttribute) || empty($displayNameAttribute)) {
            throw new LdapException('The LDAP "display name" attribute must be a non-empty string.');
        }
        $this->displayNameAttribute = $displayNameAttribute;

        if (!is_string($employeeTypeAttribute)) {
            throw new LdapException('The LDAP "employee type" attribute must be a string.');
        }
        $this->employeeTypeAttribute = $employeeTypeAttribute;
        // Multiple attribute names may be configured, e.g. "jluemployeetype,employeetype", because
        // directories often carry the employee type under different names per user population.
        $this->employeeTypeAttributes = Str::of($employeeTypeAttribute)->explode(',')
            // Not map('trim'): Collection::map passes the key as the second argument, which trim()
            // would take as its character list.
            ->map(fn (string $attribute) => trim($attribute))
            ->filter()->values()->all();

        $this->employeeTypeDefault = is_string($employeeTypeDefault) ? trim($employeeTypeDefault) : '';

        if (empty($this->employeeTypeAttributes) && $this->employeeTypeDefault === '') {
            throw new LdapException('Either the LDAP "employee type" attribute or a default employee type must be configured.');
        }

        $this->displayNameAttributeIsArray = str_contains($displayNameAttribute, ',');

        $this->legacyInvertDisplayNameOrder = (bool)$legacyInvertDisplayNameOrder;
    }

    public function getUsername(mixed $ldapEntry): string
    {
        return $this->getLdapAttributeValue($ldapEntry, $this->usernameAttribute);
    }

    public function getEmail(mixed $ldapEntry): string
    {
        return $this->getLdapAttributeValue($ldapEntry, $this->emailAttribute);
    }

    /**
     * Resolves the employee type of the entry.
     * Each configured attribute name is tried in order; the first one that carries a value wins.
     * If none of them are present, the configured default is used, so that users whose directory
     * entry simply lacks the attribute can still log in. Only when no default is configured does
     * this fail the authentication.
     */
    public function getEmployeeType(mixed $ldapEntry): string
    {
        foreach ($this->employeeTypeAttributes as $attribute) {
            $value = $this->findLdapAttributeValue($ldapEntry, $attribute);
            if ($value !== null) {
                return $value;
            }
        }

        if ($this->employeeTypeDefault !== '') {
            $this->logger?->warning('LDAP entry has no employee type, falling back to the configured default', [
                'configured_attributes' => $this->employeeTypeAttributes,
                'default' => $this->employeeTypeDefault,
                'available_attributes' => is_array($ldapEntry) ? array_keys($ldapEntry[0] ?? []) : null,
            ]);

            return $this->employeeTypeDefault;
        }

        throw new LdapException(sprintf(
            "The LDAP entry does not contain any of the employee type attributes: '%s', and no default is configured.",
            implode("', '", $this->employeeTypeAttributes)
        ));
    }

    public function getDisplayName(mixed $ldapEntry): string
    {
        if ($this->displayNameAttributeIsArray) {
            return DisplayNameBuilder::build(
                definition: $this->displayNameAttribute,
                valueResolver: fn(string $attribute) => $this->getLdapAttributeValue($ldapEntry, $attribute),
                logger: $this->logger
            );
        }

        $displayName = $this->getLdapAttributeValue($ldapEntry, $this->displayNameAttribute);
        // Handle display name inversion (e.g., "Lastname, Firstname")
        if ($this->legacyInvertDisplayNameOrder) {
            $parts = explode(", ", $displayName);
            $displayName = ($parts[1] ?? '') . ' ' . ($parts[0] ?? '');
        }
        return $displayName;
    }

    private function getLdapAttributeValue(mixed $ldapEntry, string $attribute): string
    {
        $value = $this->findLdapAttributeValue($ldapEntry, $attribute);

        if ($value === null) {
            $this->logger?->debug('LDAP misses attribute value', [
                'attribute' => $attribute,
                'attribute_node' => $ldapEntry[0][$attribute] ?? null,
                'available_attributes' => array_keys($ldapEntry[0] ?? [])
            ]);
            throw new LdapException("The LDAP entry does not contain an attribute called: '{$attribute}' that has a value.");
        }

        return $value;
    }

    /**
     * Reads a single attribute value from the entry, or null if the attribute is absent or empty.
     * Structural problems with the entry itself are still fatal.
     */
    private function findLdapAttributeValue(mixed $ldapEntry, string $attribute): ?string
    {
        if (!is_array($ldapEntry)) {
            throw new LdapException('The LDAP entry must be an array. However, ' . gettype($ldapEntry) . ' given.');
        }
        if (empty($ldapEntry)) {
            throw new LdapException('The LDAP entry is empty. Looks like the LDAP query did not return any results.');
        }

        // ldap_get_entries() lowercases all attribute names, so a configured name like "jluEmployeeType"
        // would never match. Fall back to the lowercased name before giving up.
        $key = isset($ldapEntry[0][$attribute]) ? $attribute : Str::lower($attribute);

        if (empty($ldapEntry[0][$key][0])) {
            return null;
        }
        if (!is_string($ldapEntry[0][$key][0])) {
            $this->logger?->debug('LDAP attribute value is not a string', [
                'attribute' => $key,
                'attribute_value' => $ldapEntry[0][$key][0],
                'attribute_value_type' => gettype($ldapEntry[0][$key][0]),
            ]);
            throw new LdapException("LDAP: User info attribute '{$key}' is not a string.");
        }

        return $ldapEntry[0][$key][0];
    }
}
