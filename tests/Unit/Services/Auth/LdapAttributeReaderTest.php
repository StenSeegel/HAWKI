<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\Exception\LdapException;
use App\Services\Auth\Value\Ldap\LdapAttributeReader;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the employee type resolution that used to reject valid users outright.
 *
 * A user whose directory entry carried no employee type attribute could not log in at all, even
 * though the password bind had already succeeded, because the attribute was read as mandatory.
 */
class LdapAttributeReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Builds an entry in the shape ldap_get_entries() returns: a list of entries, each attribute
     * holding a list of values, all attribute names lowercased by ext-ldap.
     */
    private function entry(array $attributes = []): array
    {
        return [
            0 => array_merge([
                'cn' => ['jdoe'],
                'mail' => ['jdoe@example.org'],
                'displayname' => ['Doe, John'],
            ], $attributes),
        ];
    }

    private function makeReader(
        string $employeeTypeAttribute = 'employeetype',
        string $employeeTypeDefault = 'guest',
        mixed $invertName = false,
        ?LoggerInterface $logger = null,
        string $displayNameAttribute = 'displayname',
    ): LdapAttributeReader {
        return new LdapAttributeReader(
            usernameAttribute: 'cn',
            emailAttribute: 'mail',
            displayNameAttribute: $displayNameAttribute,
            employeeTypeAttribute: $employeeTypeAttribute,
            legacyInvertDisplayNameOrder: $invertName,
            employeeTypeDefault: $employeeTypeDefault,
            logger: $logger,
        );
    }

    public function test_reads_employee_type_from_the_configured_attribute(): void
    {
        $reader = $this->makeReader('employeetype');

        $this->assertSame('student', $reader->getEmployeeType($this->entry(['employeetype' => ['student']])));
    }

    public function test_reads_employee_type_from_the_first_of_several_configured_attributes(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $this->assertSame('staff', $reader->getEmployeeType($this->entry(['jluemployeetype' => ['staff']])));
    }

    public function test_falls_through_to_a_later_attribute_when_the_first_is_absent(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $this->assertSame('student', $reader->getEmployeeType($this->entry(['employeetype' => ['student']])));
    }

    public function test_earlier_attribute_wins_when_several_are_present(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $entry = $this->entry(['jluemployeetype' => ['staff'], 'employeetype' => ['student']]);

        $this->assertSame('staff', $reader->getEmployeeType($entry));
    }

    public function test_skips_an_attribute_that_is_present_but_empty(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $entry = $this->entry(['jluemployeetype' => [''], 'employeetype' => ['student']]);

        $this->assertSame('student', $reader->getEmployeeType($entry));
    }

    public function test_tolerates_whitespace_in_the_attribute_list(): void
    {
        $reader = $this->makeReader(' jluemployeetype , employeetype ');

        $this->assertSame('student', $reader->getEmployeeType($this->entry(['employeetype' => ['student']])));
    }

    /**
     * ldap_get_entries() lowercases every attribute name, so a mixed-case configuration value would
     * otherwise never match and lock the user out.
     */
    #[DataProvider('miscasedAttributeProvider')]
    public function test_matches_attribute_names_case_insensitively(string $configuredAttribute): void
    {
        $reader = $this->makeReader($configuredAttribute);

        $this->assertSame('staff', $reader->getEmployeeType($this->entry(['jluemployeetype' => ['staff']])));
    }

    public static function miscasedAttributeProvider(): array
    {
        return [
            'camel case' => ['jluEmployeeType'],
            'upper case' => ['JLUEMPLOYEETYPE'],
            'exact match' => ['jluemployeetype'],
        ];
    }

    public function test_returns_the_default_when_no_configured_attribute_is_present(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $this->assertSame('guest', $reader->getEmployeeType($this->entry()));
    }

    public function test_returns_the_default_when_every_configured_attribute_is_empty(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype');

        $entry = $this->entry(['jluemployeetype' => [''], 'employeetype' => ['']]);

        $this->assertSame('guest', $reader->getEmployeeType($entry));
    }

    public function test_logs_a_warning_when_falling_back_to_the_default(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('debug')->byDefault();
        $logger->shouldReceive('warning')
            ->once()
            ->with(
                Mockery::pattern('/no employee type/i'),
                Mockery::on(fn (array $context) => $context['default'] === 'guest'
                    && $context['configured_attributes'] === ['employeetype'])
            );

        $employeeType = $this->makeReader('employeetype', 'guest', false, $logger)->getEmployeeType($this->entry());

        $this->assertSame('guest', $employeeType);
    }

    public function test_rejects_the_entry_when_no_default_is_configured(): void
    {
        $reader = $this->makeReader('jluemployeetype,employeetype', '');

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage("'jluemployeetype', 'employeetype'");

        $reader->getEmployeeType($this->entry());
    }

    public function test_a_default_alone_is_enough_configuration(): void
    {
        $reader = $this->makeReader('', 'guest');

        $this->assertSame('guest', $reader->getEmployeeType($this->entry()));
    }

    public function test_requires_either_an_attribute_or_a_default(): void
    {
        $this->expectException(LdapException::class);

        $this->makeReader('', '');
    }

    public function test_employee_type_value_must_be_a_string(): void
    {
        $reader = $this->makeReader('employeetype');

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage('is not a string');

        $reader->getEmployeeType($this->entry(['employeetype' => [['nested']]]));
    }

    public function test_username_and_email_stay_mandatory(): void
    {
        $reader = $this->makeReader('employeetype');
        $entry = $this->entry();
        unset($entry[0]['mail']);

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage("'mail'");

        $reader->getEmail($entry);
    }

    public function test_reads_username_and_email(): void
    {
        $reader = $this->makeReader();

        $this->assertSame('jdoe', $reader->getUsername($this->entry()));
        $this->assertSame('jdoe@example.org', $reader->getEmail($this->entry()));
    }

    public function test_display_name_handling_is_unaffected(): void
    {
        $this->assertSame('Doe, John', $this->makeReader()->getDisplayName($this->entry()));

        $inverting = $this->makeReader('employeetype', 'guest', true);
        $this->assertSame('John Doe', $inverting->getDisplayName($this->entry()));

        $entry = $this->entry(['givenname' => ['John'], 'sn' => ['Doe']]);

        $combining = $this->makeReader(displayNameAttribute: 'givenname,sn');
        $this->assertSame('John Doe', $combining->getDisplayName($entry));

        // A padded definition must resolve too - DisplayNameBuilder used to leave the spaces in place
        $padded = $this->makeReader(displayNameAttribute: 'givenname, sn');
        $this->assertSame('John Doe', $padded->getDisplayName($entry));
    }

    #[DataProvider('malformedEntryProvider')]
    public function test_rejects_a_structurally_invalid_entry(mixed $ldapEntry, string $expectedMessage): void
    {
        $reader = $this->makeReader('employeetype');

        $this->expectException(LdapException::class);
        $this->expectExceptionMessage($expectedMessage);

        $reader->getEmployeeType($ldapEntry);
    }

    public static function malformedEntryProvider(): array
    {
        return [
            'not an array' => ['nonsense', 'must be an array'],
            'empty array' => [[], 'is empty'],
        ];
    }
}
