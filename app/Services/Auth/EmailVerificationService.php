<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\EmailDomainRoleRule;
use App\Models\EmailVerificationCode;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\Value\Local\EmailVerificationResult;
use App\Services\EmailService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Psr\Log\LoggerInterface;

/**
 * Issues and checks the 6-digit code a self-registering local user confirms their address with.
 *
 * Only the hash of a code is stored, a code is valid for 15 minutes and survives at most
 * three wrong attempts. Every event is logged with the user id, never with the address.
 */
class EmailVerificationService
{
    public const CODE_TTL_MINUTES = 15;

    public const MAX_ATTEMPTS = 3;

    private const TOKEN_TTL_SECONDS = 900;

    public function __construct(
        private readonly EmailService            $emailService,
        private readonly EmailDomainRoleResolver $domainResolver,
        private readonly LoggerInterface         $logger,
    )
    {
    }

    /**
     * Whether a self-registration with this role has to be verified.
     * With domain filtering active every registration is verified, regardless of the role.
     */
    public function requiresVerification(?string $roleSlug, ?EmailDomainRoleRule $rule = null): bool
    {
        if ($this->domainResolver->isActive()) {
            return true;
        }

        if ($rule?->role !== null) {
            return (bool) $rule->role->require_email_verification;
        }

        if (empty($roleSlug)) {
            return false;
        }

        return (bool) Role::where('slug', $roleSlug)->value('require_email_verification');
    }

    /**
     * Whether this account is still waiting for its address to be confirmed.
     */
    public function needsVerification(User $user): bool
    {
        if (strtolower((string) $user->auth_type) !== 'local' || $user->email_verified_at !== null) {
            return false;
        }

        if (EmailVerificationCode::where('user_id', $user->id)->exists()) {
            return true;
        }

        return $this->requiresVerification($user->employeetype, $user->domainRule);
    }

    /**
     * Create a fresh code, invalidate the previous one and mail it out.
     */
    public function issue(User $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EmailVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'attempts' => 0,
            ]
        );

        $this->logger->info('E-mail verification code issued', ['user_id' => $user->id]);

        $sent = $this->emailService->sendTemplatedEmail(
            'otp',
            $user->email,
            ['{{otp}}' => $code],
            $user,
            $this->mailLanguage()
        );

        if (! $sent) {
            $this->logger->error('Failed to send e-mail verification code', ['user_id' => $user->id]);
        }

        return $sent;
    }

    /**
     * Check a submitted code and, on success, mark the address as verified.
     */
    public function verify(User $user, string $code): EmailVerificationResult
    {
        $record = EmailVerificationCode::where('user_id', $user->id)->first();

        if (! $record) {
            return new EmailVerificationResult(EmailVerificationResult::STATUS_MISSING);
        }

        if ($record->isExpired()) {
            $record->delete();
            $this->logger->info('E-mail verification code expired', ['user_id' => $user->id]);

            return new EmailVerificationResult(EmailVerificationResult::STATUS_EXPIRED);
        }

        // Hash::check uses password_verify, which compares in constant time.
        if (! Hash::check(trim($code), $record->code_hash)) {
            $record->attempts++;

            if ($record->attempts >= self::MAX_ATTEMPTS) {
                $record->delete();
                $this->logger->warning('E-mail verification code invalidated after too many attempts', [
                    'user_id' => $user->id,
                ]);

                return new EmailVerificationResult(EmailVerificationResult::STATUS_INVALID, 0);
            }

            $record->save();
            $this->logger->info('E-mail verification attempt failed', [
                'user_id' => $user->id,
                'attempts' => $record->attempts,
            ]);

            return new EmailVerificationResult(
                EmailVerificationResult::STATUS_INVALID,
                self::MAX_ATTEMPTS - $record->attempts
            );
        }

        $record->delete();

        // Saving the user triggers the observer, which now attaches the Orchid role.
        $user->email_verified_at = now();
        $user->save();

        $this->logger->info('E-mail address verified', ['user_id' => $user->id]);

        return new EmailVerificationResult(EmailVerificationResult::STATUS_VERIFIED);
    }

    /**
     * Correct the address of an unverified account and send a new code.
     *
     * @return string One of: verified_already, email_taken, domain_not_allowed, ok
     */
    public function changeAddress(User $user, string $email): string
    {
        if ($user->email_verified_at !== null || strtolower((string) $user->auth_type) !== 'local') {
            return 'verified_already';
        }

        $email = trim($email);

        if (User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            return 'email_taken';
        }

        $rule = null;

        if ($this->domainResolver->isActive()) {
            $rule = $this->domainResolver->match($email);

            if (! $rule || ! $rule->role) {
                return 'domain_not_allowed';
            }
        }

        $user->email = $email;

        if ($rule) {
            $user->employeetype = $rule->role->slug;
            $user->domain_rule_id = $rule->id;
        }

        $user->save();

        $this->logger->info('E-mail address changed before verification', [
            'user_id' => $user->id,
            'domain_rule_id' => $user->domain_rule_id,
        ]);

        $this->issue($user);

        return 'ok';
    }

    /**
     * Short-lived token binding the unauthenticated code step to one account.
     */
    public function stepToken(User $user): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $user->id,
            'exp' => now()->addSeconds(self::TOKEN_TTL_SECONDS)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The unverified local account a step token belongs to, if the token is still valid.
     */
    public function userFromToken(?string $token): ?User
    {
        if (empty($token)) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload) || ($payload['exp'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        $user = User::find($payload['user_id'] ?? null);

        if (! $user || $user->email_verified_at !== null || strtolower((string) $user->auth_type) !== 'local') {
            return null;
        }

        return $user;
    }

    /**
     * Address shown on the code step, with the local part shortened.
     */
    public function maskEmail(string $email): string
    {
        $position = strrpos($email, '@');

        if ($position === false || $position === 0) {
            return $email;
        }

        $local = substr($email, 0, $position);
        $domain = substr($email, $position);

        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1).'***'.$domain;
        }

        return mb_substr($local, 0, 1).str_repeat('*', 3).mb_substr($local, -1).$domain;
    }

    /**
     * The otp template is seeded in de and en; anything else falls back inside the mail service.
     */
    private function mailLanguage(): string
    {
        $locale = app()->getLocale();

        return in_array($locale, ['de', 'en'], true) ? $locale : 'de';
    }
}
