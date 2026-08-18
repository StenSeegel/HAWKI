<?php
declare(strict_types=1);

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\ChainedAuthService;
use App\Services\Auth\Contract\AuthServiceInterface;
use App\Services\Auth\Contract\AuthServiceWithPostProcessingInterface;
use App\Services\Auth\Exception\AuthFailedException;
use App\Services\Auth\Value\AuthenticatedUserInfo;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the post-login dispatch of {@see ChainedAuthService}.
 *
 * The bug these tests pin: the hooks used to walk the whole chain, so LocalAuthService - which is
 * unshifted to the FRONT of the chain whenever auth.local_authentication is on - rejected every
 * LDAP user that still had to register, with a plain 200 response and nothing in the log.
 */
class ChainedAuthServiceTest extends TestCase
{
    /**
     * The real defect. LocalAuthService sits first in the chain and always returns a failure from
     * afterLoginWithoutUser(); LDAP authenticates. The chain must not surface the local rejection.
     */
    public function test_a_service_that_did_not_authenticate_cannot_reject_the_login(): void
    {
        $local = $this->serviceThatFailsToAuthenticate(
            afterWithoutUser: $this->jsonResponse('User account not found or deactivated.')
        );
        $ldap = $this->serviceThatAuthenticates();

        $chain = new ChainedAuthService($local, $ldap);
        $chain->authenticate(new Request);

        $this->assertNull(
            $chain->afterLoginWithoutUser($this->userInfo(), new Request),
            'LocalAuthService must not veto a login that LdapService authenticated'
        );
    }

    public function test_the_same_holds_for_the_with_user_hook(): void
    {
        $local = $this->serviceThatFailsToAuthenticate(
            afterWithUser: $this->jsonResponse('local user must complete registration')
        );
        $ldap = $this->serviceThatAuthenticates();

        $chain = new ChainedAuthService($local, $ldap);
        $chain->authenticate(new Request);

        $this->assertNull($chain->afterLoginWithUser(new User, new Request, $this->userInfo()));
    }

    /**
     * The other half of the contract: the winning service's own hooks MUST still run, otherwise this
     * fix would silently disable LocalAuthService's legitimate rejection of deactivated accounts.
     */
    public function test_the_authenticating_service_still_runs_its_own_hooks(): void
    {
        $expected = $this->jsonResponse('User account not found or deactivated.');
        $local = $this->serviceThatAuthenticates(afterWithoutUser: $expected);

        $chain = new ChainedAuthService($local, $this->serviceThatFailsToAuthenticate());
        $chain->authenticate(new Request);

        $this->assertSame($expected, $chain->afterLoginWithoutUser($this->userInfo(), new Request));
    }

    public function test_hooks_do_nothing_when_authenticate_was_never_called(): void
    {
        $chain = new ChainedAuthService(
            $this->serviceThatAuthenticates(afterWithoutUser: $this->jsonResponse('nope'))
        );

        $this->assertNull($chain->afterLoginWithoutUser($this->userInfo(), new Request));
    }

    /**
     * A failed login must not leave the previous winner behind for the next call to post-process.
     */
    public function test_a_failed_authentication_clears_the_remembered_service(): void
    {
        $winner = $this->serviceThatAuthenticates(afterWithoutUser: $this->jsonResponse('stale'));
        $chain = new ChainedAuthService($winner);

        $chain->authenticate(new Request);
        $this->assertNotNull($chain->afterLoginWithoutUser($this->userInfo(), new Request));

        $failing = new ChainedAuthService($this->serviceThatFailsToAuthenticate());
        try {
            $failing->authenticate(new Request);
            $this->fail('expected AuthFailedException');
        } catch (AuthFailedException) {
            // expected
        }

        $this->assertNull($failing->afterLoginWithoutUser($this->userInfo(), new Request));
    }

    public function test_a_winning_service_without_post_processing_is_handled(): void
    {
        // LdapService does not implement the post-processing interface at all.
        $bare = new class implements AuthServiceInterface
        {
            public function authenticate(Request $request): AuthenticatedUserInfo|Response
            {
                return new AuthenticatedUserInfo(
                    username: 'gz488',
                    displayName: 'Sten Seegel',
                    email: 'sten.seegel@uni-giessen.de',
                    employeeType: '21',
                );
            }
        };

        $chain = new ChainedAuthService($bare, $this->serviceThatFailsToAuthenticate());
        $chain->authenticate(new Request);

        $this->assertNull($chain->afterLoginWithoutUser($this->userInfo(), new Request));
    }

    private function userInfo(): AuthenticatedUserInfo
    {
        return new AuthenticatedUserInfo(
            username: 'J_E2J6C5E',
            displayName: 'Jonathan Baum',
            email: 'jonathan.baum-1@ext.uni-giessen.de',
            employeeType: 'guest',
        );
    }

    private function jsonResponse(string $message): Response
    {
        return new Response(json_encode(['success' => false, 'message' => $message]));
    }

    private function serviceThatAuthenticates(
        ?Response $afterWithUser = null,
        ?Response $afterWithoutUser = null
    ): AuthServiceInterface {
        return $this->stubService(true, $afterWithUser, $afterWithoutUser);
    }

    private function serviceThatFailsToAuthenticate(
        ?Response $afterWithUser = null,
        ?Response $afterWithoutUser = null
    ): AuthServiceInterface {
        return $this->stubService(false, $afterWithUser, $afterWithoutUser);
    }

    private function stubService(
        bool $succeeds,
        ?Response $afterWithUser,
        ?Response $afterWithoutUser
    ): AuthServiceInterface {
        return new class($succeeds, $afterWithUser, $afterWithoutUser) implements AuthServiceInterface, AuthServiceWithPostProcessingInterface
        {
            public function __construct(
                private readonly bool $succeeds,
                private readonly ?Response $afterWithUser,
                private readonly ?Response $afterWithoutUser
            ) {}

            public function authenticate(Request $request): AuthenticatedUserInfo|Response
            {
                if (!$this->succeeds) {
                    throw new AuthFailedException('stub refuses to authenticate');
                }

                return new AuthenticatedUserInfo(
                    username: 'J_E2J6C5E',
                    displayName: 'Jonathan Baum',
                    email: 'jonathan.baum-1@ext.uni-giessen.de',
                    employeeType: 'guest',
                );
            }

            public function afterLoginWithUser(User $user, Request $request, AuthenticatedUserInfo $userInfo): Response|null
            {
                return $this->afterWithUser;
            }

            public function afterLoginWithoutUser(AuthenticatedUserInfo $userInfo, Request $request): Response|null
            {
                return $this->afterWithoutUser;
            }
        };
    }
}
