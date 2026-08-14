<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\Value\AuthenticatedUserInfo;
use App\Services\Auth\Value\Ldap\LdapAttributeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reproduces the JLU directory entries verbatim to locate where an external account is rejected.
 *
 * Two real entries, identical except for one attribute:
 *
 *   internal  dn: cn=sten seegel,ou=Hochschulrechenzentrum,o=Universitaet Giessen,c=DE
 *             uid, displayName, mail, jluEmployeeType, eduPersonAffiliation
 *
 *   external  dn: cn=ACC01-0000362676,ou=Externe,ou=Personen,o=Universitaet Giessen,c=DE
 *             uid, displayName, mail
 *
 * The external entry carries no jluEmployeeType and no eduPersonAffiliation. Neither carries a
 * readable cn - it exists only inside the DN.
 */
class LdapExternalUserLoginTest extends TestCase
{
    /**
     * Builds the array ext-ldap actually hands back, rather than a convenient approximation:
     * attribute names lowercased, every value list carrying its own "count", the attribute names
     * repeated under numeric indices, plus the entry "count" and "dn".
     *
     * @param  array<string, string[]>  $attributes  Attribute name => values, in server order
     */
    private function ldapGetEntries(string $dn, array $attributes): array
    {
        $entry = [];
        $index = 0;

        foreach ($attributes as $name => $values) {
            $entry[strtolower($name)] = ['count' => count($values)] + array_values($values);
            $entry[$index++] = strtolower($name);
        }

        $entry['count'] = $index;
        $entry['dn'] = $dn;

        return ['count' => 1, 0 => $entry];
    }

    private function externalEntry(): array
    {
        return $this->ldapGetEntries(
            'cn=ACC01-0000362676,ou=Externe,ou=Personen,o=Universitaet Giessen,c=DE',
            [
                'mail' => ['jonathan.baum-1@ext.uni-giessen.de'],
                'displayName' => ['Jonathan Baum'],
                'uid' => ['J_E2J6C5E'],
            ]
        );
    }

    private function internalEntry(): array
    {
        return $this->ldapGetEntries(
            'cn=sten seegel,ou=Hochschulrechenzentrum,o=Universitaet Giessen,c=DE',
            [
                'uid' => ['gz488'],
                'displayName' => ['Sten Seegel'],
                'jluEmployeeType' => ['21'],
                'mail' => ['sten.seegel@uni-giessen.de'],
                'eduPersonAffiliation' => ['staff', 'member', 'employee'],
            ]
        );
    }

    /**
     * The staging configuration: usernames in the database are a mix of "gz488" and "J_N7T5N4P",
     * so the username attribute resolves to uid, and the employee type default is "11".
     */
    private function stagingReader(): LdapAttributeReader
    {
        return new LdapAttributeReader(
            usernameAttribute: 'uid',
            emailAttribute: 'mail',
            displayNameAttribute: 'displayname',
            employeeTypeAttribute: 'jluemployeetype',
            legacyInvertDisplayNameOrder: true,
            employeeTypeDefault: '11',
            logger: new NullLogger,
        );
    }

    /**
     * The exact call LdapService makes once the password bind succeeded. If an external account is
     * rejected during authentication, it has to fail here.
     */
    private function buildUserInfo(LdapAttributeReader $reader, array $ldapEntry): AuthenticatedUserInfo
    {
        return new AuthenticatedUserInfo(
            username: $reader->getUsername($ldapEntry),
            displayName: $reader->getDisplayName($ldapEntry),
            email: $reader->getEmail($ldapEntry),
            employeeType: $reader->getEmployeeType($ldapEntry),
        );
    }

    public function test_the_external_account_resolves_completely(): void
    {
        $userInfo = $this->buildUserInfo($this->stagingReader(), $this->externalEntry());

        $this->assertSame('J_E2J6C5E', $userInfo->username);
        $this->assertSame('jonathan.baum-1@ext.uni-giessen.de', $userInfo->email);
        $this->assertSame('11', $userInfo->employeeType, 'the configured default has to stand in');
    }

    public function test_the_internal_account_resolves_completely(): void
    {
        $userInfo = $this->buildUserInfo($this->stagingReader(), $this->internalEntry());

        $this->assertSame('gz488', $userInfo->username);
        $this->assertSame('sten.seegel@uni-giessen.de', $userInfo->email);
        $this->assertSame('21', $userInfo->employeeType);
    }

    /**
     * Whatever separates the two accounts, it must not be anything the reader does: apart from the
     * employee type, both entries have to come out the same shape.
     */
    public function test_both_accounts_produce_the_same_shaped_result(): void
    {
        $external = $this->buildUserInfo($this->stagingReader(), $this->externalEntry());
        $internal = $this->buildUserInfo($this->stagingReader(), $this->internalEntry());

        foreach (['username', 'displayName', 'email', 'employeeType'] as $field) {
            $this->assertNotSame('', trim($external->$field), "external account has an empty {$field}");
            $this->assertNotSame('', trim($internal->$field), "internal account has an empty {$field}");
        }
    }

    /**
     * LDAP_INVERT_NAME defaults to true and splits on ", " to turn "Doe, John" into "John Doe".
     * The JLU directory delivers displayName already in reading order, so the inversion has nothing
     * to split on and prepends a space instead - to both populations equally, so it is not what
     * separates them, but it does end up in the database as the user's name.
     */
    #[DataProvider('displayNameProvider')]
    public function test_display_name_inversion_only_adds_a_leading_space(string $entry, string $expected): void
    {
        $reader = $this->stagingReader();
        $ldapEntry = $this->ldapGetEntries('cn=x', ['displayName' => [$entry], 'uid' => ['x'], 'mail' => ['x@y.z']]);

        $this->assertSame($expected, $reader->getDisplayName($ldapEntry));
    }

    public static function displayNameProvider(): array
    {
        return [
            'external, no comma' => ['Jonathan Baum', ' Jonathan Baum'],
            'internal, no comma' => ['Sten Seegel', ' Sten Seegel'],
            'inverted as intended' => ['Seegel, Sten', 'Sten Seegel'],
        ];
    }

    /**
     * The state before the fix, kept as the regression guard: with no default configured the
     * external account is rejected and the internal one is not.
     */
    public function test_without_a_default_the_external_account_is_the_only_one_rejected(): void
    {
        $reader = new LdapAttributeReader(
            usernameAttribute: 'uid',
            emailAttribute: 'mail',
            displayNameAttribute: 'displayname',
            employeeTypeAttribute: 'jluemployeetype',
            legacyInvertDisplayNameOrder: true,
            employeeTypeDefault: '',
            logger: new NullLogger,
        );

        $this->assertSame('21', $reader->getEmployeeType($this->internalEntry()));

        $this->expectException(\App\Services\Auth\Exception\LdapException::class);
        $reader->getEmployeeType($this->externalEntry());
    }
}
