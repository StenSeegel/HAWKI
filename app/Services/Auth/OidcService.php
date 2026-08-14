<?php

namespace App\Services\Auth;

use App\Services\Auth\Contract\AuthServiceInterface;
use App\Services\Auth\Contract\AuthServiceWithLogoutRedirectInterface;
use App\Services\Auth\Exception\AuthFailedException;
use App\Services\Auth\Util\AuthRedirectBuilder;
use App\Services\Auth\Util\DisplayNameBuilder;
use App\Services\Auth\Value\AuthenticatedUserInfo;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jumbojett\OpenIDConnectClient;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

#[Singleton]
readonly class OidcService implements AuthServiceInterface, AuthServiceWithLogoutRedirectInterface
{
    /**
     * The employee type attribute definition split into single candidate claim names.
     * The first candidate the provider actually delivers wins.
     * @var string[]
     */
    private array $employeeTypeAttributes;

    /**
     * Value used when the provider delivers none of the configured claims.
     * An empty string means "no default" and restores the legacy behavior of failing the login.
     */
    private string $employeeTypeDefault;

    public function __construct(
        #[Config('open_id_connect.oidc_idp')]
        private string          $idp,
        #[Config('open_id_connect.oidc_client_id')]
        private string          $clientId,
        #[Config('open_id_connect.oidc_client_secret')]
        #[SensitiveParameter]
        private string          $clientSecret,
        #[Config('open_id_connect.oidc_scopes')]
        private array           $scopes,
        #[Config('open_id_connect.attribute_map.username')]
        private string          $usernameAttribute,
        #[Config('open_id_connect.attribute_map.email')]
        private string          $emailAttribute,
        #[Config('open_id_connect.attribute_map.employeetype')]
        private string          $employeeTypeAttribute,
        #[Config('open_id_connect.attribute_map.name')]
        private string          $nameAttribute,
        #[Config('open_id_connect.oidc_logout_path')]
        private string          $logoutPath,
        private LoggerInterface $logger,
        #[Config('open_id_connect.employeetype_default')]
        mixed                   $employeeTypeDefault = 'guest',
    )
    {
        // Multiple claim names may be configured, e.g. "employeetype,groups", because providers
        // deliver the employee type under different claims per user population.
        $this->employeeTypeAttributes = Str::of($employeeTypeAttribute)->explode(',')
            // Not map('trim'): Collection::map passes the key as the second argument, which trim()
            // would take as its character list.
            ->map(fn (string $attribute) => trim($attribute))
            ->filter()->values()->all();
        $this->employeeTypeDefault = is_string($employeeTypeDefault) ? trim($employeeTypeDefault) : '';
    }

    /**
     * @inheritDoc
     */
    public function authenticate(Request $request): AuthenticatedUserInfo|Response
    {
        if (empty($this->idp) || empty($this->clientId) || empty($this->clientSecret)) {
            throw new AuthFailedException('OIDC configuration variables are not set properly.', 500);
        }

        $oidc = new OpenIDConnectClient($this->idp, $this->clientId, $this->clientSecret);
        $oidc->addScope($this->scopes);

        try {
            // Attempt to authenticate the user
            if ($oidc->authenticate()) {
                $request->session()->put('oidc_id_token', $oidc->getIdToken());
                $this->logger->debug('Authenticated OIDC user');
            }
        } catch (\Throwable $e) {
            throw new AuthFailedException('OIDC authentication failed', 401, $e);
        }

        try {
            return new AuthenticatedUserInfo(
                username: $this->getUserInfoOrFail($oidc, $this->usernameAttribute),
                displayName: DisplayNameBuilder::build(
                    definition: $this->nameAttribute,
                    valueResolver: fn(string $field) => $this->getUserInfoOrFail($oidc, $field),
                    logger: $this->logger
                ),
                email: $this->getUserInfoOrFail($oidc, $this->emailAttribute),
                employeeType: $this->resolveEmployeeType($oidc),
            );
        } catch (\Exception $e) {
            throw new AuthFailedException('Failed to resolve userdata for OIDC auth', 500, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function getLogoutResponse(Request $request): ?RedirectResponse
    {
        $idTokenHint = $request->session()->get('oidc_id_token');

        $params = [];
        if (!empty($idTokenHint)) {
            $params = [
                'id_token_hint' => $idTokenHint
            ];
        }

        return AuthRedirectBuilder::build(
            $this->logoutPath,
            [
                'post_logout_redirect_uri' => 'login'
            ],
            $params
        );
    }

    /**
     * Resolves the employee type of the authenticated user.
     * Each configured claim is tried in order; the first one the provider delivers wins.
     * If the provider delivers none of them, the configured default is used, so that users whose
     * account simply carries no employee type can still log in. Only when no default is configured
     * does this fail the authentication.
     */
    private function resolveEmployeeType(OpenIDConnectClient $oidc): string
    {
        foreach ($this->employeeTypeAttributes as $attribute) {
            $value = $this->firstUsableValue($oidc->requestUserInfo($attribute));
            if ($value !== null) {
                return $value;
            }
        }

        if ($this->employeeTypeDefault !== '') {
            $this->logger->warning('OIDC user info has no employee type, falling back to the configured default', [
                'configured_attributes' => $this->employeeTypeAttributes,
                'default' => $this->employeeTypeDefault,
            ]);

            return $this->employeeTypeDefault;
        }

        throw new \RuntimeException(sprintf(
            "OIDC: User info contains none of the employee type attributes: '%s', and no default is configured.",
            implode("', '", $this->employeeTypeAttributes)
        ));
    }

    /**
     * Normalizes a claim value to a usable string, or null if it carries nothing usable.
     * Claims such as "groups" or "roles" are commonly delivered as arrays, so the first usable
     * entry is taken.
     */
    private function firstUsableValue(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $entry) {
                $usable = $this->firstUsableValue($entry);
                if ($usable !== null) {
                    return $usable;
                }
            }

            return null;
        }

        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function getUserInfoOrFail(OpenIDConnectClient $oidc, string $var): string
    {
        $value = $oidc->requestUserInfo($var);
        if (empty($value)) {
            throw new \RuntimeException("OIDC: User info attribute '{$var}' is missing or empty.");
        }
        if (!is_string($value)) {
            throw new \RuntimeException("OIDC: User info attribute '{$var}' is not a string.");
        }
        return $value;
    }
}
