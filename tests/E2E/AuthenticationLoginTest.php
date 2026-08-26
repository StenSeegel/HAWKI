<?php

declare(strict_types=1);

namespace Tests\E2E;

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Drives a real login against a deployed HAWKI, with a real directory account, over HTTP.
 *
 * Everything below the controller is already covered by the unit tests. What they cannot show is
 * whether the deployment in front of them is configured so that this account gets through: the
 * attribute map, the employee type default, the session cookie, and the redirect the browser is
 * told to follow all live on the server, not in this repository.
 *
 * It is excluded from the default run by its group, so `phpunit` stays offline, and it also skips
 * itself when no credentials are configured. Configure it in .env, which is git-ignored and already
 * holds the test account:
 *
 *   E2E_BASE_URL=https://ki-test.hrz.uni-giessen.de   (this is the default)
 *   TEST_USER_NAME=...
 *   TEST_USER_PASS=...
 *   E2E_EXPECT=handshake|register|any                 (default: any)
 *   E2E_INSECURE=true                                 (skip TLS verification)
 *   E2E_PROXY=http://...                              (default: no proxy at all)
 *
 * Run it with: vendor/bin/phpunit --group e2e
 */
#[Group('e2e')]
class AuthenticationLoginTest extends TestCase
{
    private const DEFAULT_BASE_URL = 'https://ki-test.hrz.uni-giessen.de';

    private static ?array $dotenv = null;

    private string $baseUrl;

    private string $account;

    private string $password;

    private CookieJar $cookies;

    private Client $http;

    protected function setUp(): void
    {
        $this->account = (string) self::env('TEST_USER_NAME', '');
        $this->password = (string) self::env('TEST_USER_PASS', '');
        $this->baseUrl = rtrim((string) self::env('E2E_BASE_URL', self::DEFAULT_BASE_URL), '/');

        if ($this->account === '' || $this->password === '') {
            $this->markTestSkipped('Set TEST_USER_NAME and TEST_USER_PASS to run the authentication end-to-end test.');
        }

        $this->cookies = new CookieJar;
        $this->http = new Client([
            'base_uri' => $this->baseUrl.'/',
            'cookies' => $this->cookies,
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 30,
            'verify' => ! self::truthy(self::env('E2E_INSECURE', 'false')),
            // Guzzle otherwise adopts HTTPS_PROXY from the shell. On the JLU network that proxy only
            // reaches the internet, so an internal host would fail here for a reason that has nothing
            // to do with authentication. An empty string means "no proxy" to curl.
            'proxy' => (string) self::env('E2E_PROXY', ''),
            'headers' => ['User-Agent' => 'HAWKI-e2e-auth-test'],
        ]);
    }

    /**
     * The login form is what hands out the session cookie and the CSRF token, so a login cannot even
     * be attempted until this works. Failing here means the deployment is down or not serving the
     * login page, which is worth distinguishing from a rejected account.
     */
    public function test_the_login_page_issues_a_session_and_a_csrf_token(): void
    {
        $response = $this->http->get('login');

        $this->assertSame(200, $response->getStatusCode(), $this->context('GET /login', $response));
        $this->assertNotSame('', $this->csrfTokenFrom($response), 'The login form carries no _token field.');
        $this->assertNotEmpty($this->cookies->toArray(), 'The login page set no session cookie.');
    }

    /**
     * The whole chain in one pass, because every step depends on the session the previous one
     * established: form -> POST /req/login -> follow the redirect the server hands back.
     *
     * Both landing pages count as a successful authentication. /handshake means the account already
     * has a user row, /register means it authenticated but has not registered yet - the state a
     * fresh test account is in until someone completes the registration once.
     */
    public function test_the_test_account_authenticates_and_reaches_its_landing_page(): void
    {
        $token = $this->csrfTokenFrom($this->http->get('login'));

        $login = $this->postLogin($token, $this->password);
        $body = $this->json($login, 'POST /req/login');

        $this->assertTrue(
            $body['success'] ?? false,
            sprintf(
                'Login failed for %s: %s',
                $this->account,
                $body['error'] ?? $body['message'] ?? 'no reason reported'
            )
        );

        $target = (string) ($body['redirectUri'] ?? '');
        $this->assertContains($target, ['/handshake', '/register'], 'Unexpected redirect target after login.');

        $expected = strtolower((string) self::env('E2E_EXPECT', 'any'));
        if ($expected !== 'any') {
            $this->assertSame('/'.$expected, $target, 'The account did not land where E2E_EXPECT says it should.');
        }

        // Following it proves the session really carries the login. A misconfigured session or a
        // failed post-login hook shows up as a redirect straight back to /login.
        $landing = $this->http->get(ltrim($target, '/'));

        $this->assertSame(200, $landing->getStatusCode(), $this->context("GET {$target}", $landing));
        $this->assertStringNotContainsString(
            'id="hawkiLoginForm"',
            (string) $landing->getBody(),
            "{$target} served the login form again, so the session did not survive the login."
        );

        $this->http->get('logout');
    }

    /**
     * The counter-check: a login that should fail must fail. Without it, a deployment that waves
     * everyone through would pass the test above.
     *
     * It also asserts that the server states a reason, which is the behaviour added when the login
     * form started surfacing server side errors instead of flattening them to "Login Failed!".
     */
    public function test_a_wrong_password_is_rejected_with_a_reported_reason(): void
    {
        $token = $this->csrfTokenFrom($this->http->get('login'));

        $body = $this->json(
            $this->postLogin($token, $this->password.'-definitely-not-the-password'),
            'POST /req/login with a wrong password'
        );

        $this->assertFalse($body['success'] ?? true, 'A wrong password was accepted.');
        $this->assertNotSame('', trim((string) ($body['error'] ?? '')), 'The rejection carried no reason.');
    }

    private function postLogin(string $token, string $password): ResponseInterface
    {
        return $this->http->post('req/login', [
            'headers' => [
                'X-CSRF-TOKEN' => $token,
                'Accept' => 'application/json',
            ],
            'form_params' => [
                'account' => $this->account,
                'password' => $password,
            ],
        ]);
    }

    /**
     * Reads the token out of the form rather than the meta tag, because that is the one the login
     * page's own JavaScript sends.
     */
    private function csrfTokenFrom(ResponseInterface $response): string
    {
        $html = (string) $response->getBody();

        return preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response, string $what): array
    {
        $this->assertSame(200, $response->getStatusCode(), $this->context($what, $response));

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded, $this->context("{$what} did not answer with JSON", $response));

        return $decoded;
    }

    private function context(string $what, ResponseInterface $response): string
    {
        return sprintf(
            "%s against %s answered %d:\n%s",
            $what,
            $this->baseUrl,
            $response->getStatusCode(),
            substr((string) $response->getBody(), 0, 500)
        );
    }

    /**
     * The credentials live in .env, which PHPUnit does not load - this is a plain test case, not a
     * Laravel one, so that the test talks to the deployment instead of booting a local application.
     */
    private static function env(string $key, string $default): string
    {
        $fromShell = getenv($key);
        if (is_string($fromShell) && $fromShell !== '') {
            return $fromShell;
        }

        if (self::$dotenv === null) {
            $root = dirname(__DIR__, 2);
            self::$dotenv = is_file($root.'/.env')
                ? Dotenv::createArrayBacked([$root])->load()
                : [];
        }

        $value = self::$dotenv[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
