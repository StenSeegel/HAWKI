<?php
declare(strict_types=1);


namespace App\Services\Auth;


use App\Models\User;
use App\Services\Auth\Contract\AuthServiceInterface;
use App\Services\Auth\Contract\AuthServiceWithCredentialsInterface;
use App\Services\Auth\Contract\AuthServiceWithLogoutRedirectInterface;
use App\Services\Auth\Contract\AuthServiceWithPostProcessingInterface;
use App\Services\Auth\Exception\AuthFailedException;
use App\Services\Auth\Value\AuthenticatedUserInfo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An authentication service that chains multiple authentication services together.
 * It tries to authenticate using each service in order until one succeeds or all fail.
 */
class ChainedAuthService implements AuthServiceInterface,
    AuthServiceWithCredentialsInterface,
    AuthServiceWithLogoutRedirectInterface,
    AuthServiceWithPostProcessingInterface
{
    /**
     * @var array<AuthServiceInterface> $services
     */
    private array $services;

    /**
     * The service that actually authenticated the current user, set by {@see authenticate()}.
     *
     * Only this service may run the post-login hooks. Letting every service in the chain run them
     * lets one service reject a login it had no part in - which is exactly how LocalAuthService
     * used to break every LDAP user that still had to register.
     *
     * @var AuthServiceInterface|null $authenticatedService
     */
    private AuthServiceInterface|null $authenticatedService = null;

    public function __construct(
        AuthServiceInterface ...$services
    )
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     */
    public function useCredentials(string $username, string $password): void
    {
        foreach ($this->services as $service) {
            if ($service instanceof AuthServiceWithCredentialsInterface) {
                $service->useCredentials($username, $password);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function forgetCredentials(): void
    {
        foreach ($this->services as $service) {
            if ($service instanceof AuthServiceWithCredentialsInterface) {
                $service->forgetCredentials();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): AuthenticatedUserInfo|Response
    {
        $this->authenticatedService = null;

        foreach ($this->services as $service) {
            try {
                $result = $service->authenticate($request);
                $this->authenticatedService = $service;

                return $result;
            } catch (AuthFailedException) {
                // Try the next service, keep the exception for debugging purposes
            }
        }

        throw new AuthFailedException(
            'All authentication services failed to authenticate the user'
        );
    }

    /**
     * @inheritDoc
     */
    public function getLogoutResponse(Request $request): ?RedirectResponse
    {
        foreach ($this->services as $service) {
            if ($service instanceof AuthServiceWithLogoutRedirectInterface) {
                $response = $service->getLogoutResponse($request);
                if ($response !== null) {
                    return $response;
                }
            }
        }

        return null;
    }

    /**
     * @param AuthenticatedUserInfo $userInfo
     * @inheritDoc
     */
    public function afterLoginWithUser(User $user, Request $request, AuthenticatedUserInfo $userInfo): Response|null
    {
        $service = $this->getPostProcessingServiceForCurrentLogin();

        return $service?->afterLoginWithUser($user, $request, $userInfo);
    }

    /**
     * @inheritDoc
     */
    public function afterLoginWithoutUser(AuthenticatedUserInfo $userInfo, Request $request): Response|null
    {
        $service = $this->getPostProcessingServiceForCurrentLogin();

        return $service?->afterLoginWithoutUser($userInfo, $request);
    }

    /**
     * Returns the service that authenticated the current user, but only if it wants to post-process
     * the login. Returns null if authentication has not run, has failed, or the winning service has
     * no post-processing to do.
     *
     * The hooks deliberately do NOT fall back to walking the whole chain: a service that did not
     * authenticate this user cannot judge whether the login is valid.
     */
    private function getPostProcessingServiceForCurrentLogin(): AuthServiceWithPostProcessingInterface|null
    {
        return $this->authenticatedService instanceof AuthServiceWithPostProcessingInterface
            ? $this->authenticatedService
            : null;
    }
}
