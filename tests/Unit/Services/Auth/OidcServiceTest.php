<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\OidcService;
use Jumbojett\OpenIDConnectClient;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * Covers the employee type resolution that used to reject valid users outright.
 *
 * Two ways an authenticated user could be locked out: the provider delivering no employee type
 * claim at all, and the claim being an array - which "groups" and "roles", the claims the config
 * itself suggests using, virtually always are.
 *
 * These tests target resolveEmployeeType() rather than authenticate(), because authenticate()
 * constructs its OpenIDConnectClient internally and so cannot be exercised without a live provider.
 */
class OidcServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(
        string $employeeTypeAttribute = 'employeetype',
        string $employeeTypeDefault = 'guest',
        ?LoggerInterface $logger = null,
    ): OidcService {
        return new OidcService(
            'https://idp.example.org',
            'client-id',
            'client-secret',
            ['profile', 'email'],
            'preferred_username',
            'email',
            $employeeTypeAttribute,
            'name',
            '',
            $logger ?? new NullLogger,
            $employeeTypeDefault,
        );
    }

    /**
     * @param  array<string, mixed>  $claims  Claim name => value the provider would return
     */
    private function clientReturning(array $claims): OpenIDConnectClient
    {
        $client = Mockery::mock(OpenIDConnectClient::class);
        $client->shouldReceive('requestUserInfo')
            ->andReturnUsing(fn ($attribute = null) => $claims[$attribute] ?? null);

        return $client;
    }

    private function resolve(OidcService $service, array $claims): string
    {
        return (new ReflectionMethod($service, 'resolveEmployeeType'))
            ->invoke($service, $this->clientReturning($claims));
    }

    public function test_reads_employee_type_from_the_configured_claim(): void
    {
        $this->assertSame('staff', $this->resolve($this->makeService('employeetype'), ['employeetype' => 'staff']));
    }

    public function test_falls_through_to_a_later_claim_when_the_first_is_absent(): void
    {
        $service = $this->makeService('employeetype,groups');

        $this->assertSame('students', $this->resolve($service, ['groups' => 'students']));
    }

    public function test_earlier_claim_wins_when_several_are_delivered(): void
    {
        $service = $this->makeService('employeetype,groups');

        $this->assertSame('staff', $this->resolve($service, ['employeetype' => 'staff', 'groups' => 'students']));
    }

    public function test_tolerates_whitespace_in_the_claim_list(): void
    {
        $service = $this->makeService(' employeetype , groups ');

        $this->assertSame('students', $this->resolve($service, ['groups' => 'students']));
    }

    /**
     * "groups" and "roles" are delivered as arrays by virtually every provider.
     */
    #[DataProvider('arrayClaimProvider')]
    public function test_uses_the_first_usable_entry_of_an_array_claim(array $claimValue, string $expected): void
    {
        $service = $this->makeService('groups');

        $this->assertSame($expected, $this->resolve($service, ['groups' => $claimValue]));
    }

    public static function arrayClaimProvider(): array
    {
        return [
            'single entry' => [['faculty'], 'faculty'],
            'first of several' => [['faculty', 'staff'], 'faculty'],
            'skips blanks' => [['', null, 'lecturer'], 'lecturer'],
            'nested' => [[['tutor', 'staff']], 'tutor'],
            'trims' => [['  faculty  '], 'faculty'],
        ];
    }

    #[DataProvider('unusableClaimProvider')]
    public function test_treats_an_unusable_claim_value_as_absent(mixed $claimValue): void
    {
        $service = $this->makeService('employeetype');

        $this->assertSame('guest', $this->resolve($service, ['employeetype' => $claimValue]));
    }

    public static function unusableClaimProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'empty array' => [[]],
            'array of blanks' => [['', null]],
            'boolean' => [true],
            'object' => [new \stdClass],
        ];
    }

    public function test_casts_a_numeric_claim_to_a_string(): void
    {
        $this->assertSame('42', $this->resolve($this->makeService('employeetype'), ['employeetype' => 42]));
    }

    public function test_returns_the_default_when_no_configured_claim_is_delivered(): void
    {
        $service = $this->makeService('employeetype,groups');

        $this->assertSame('guest', $this->resolve($service, []));
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

        $employeeType = $this->resolve($this->makeService('employeetype', 'guest', $logger), []);

        $this->assertSame('guest', $employeeType);
    }

    public function test_rejects_the_user_info_when_no_default_is_configured(): void
    {
        $service = $this->makeService('employeetype,groups', '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'employeetype', 'groups'");

        $this->resolve($service, []);
    }

    public function test_a_default_alone_is_enough_configuration(): void
    {
        $this->assertSame('guest', $this->resolve($this->makeService('', 'guest'), []));
    }
}
