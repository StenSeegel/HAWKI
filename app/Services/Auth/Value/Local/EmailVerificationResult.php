<?php

declare(strict_types=1);

namespace App\Services\Auth\Value\Local;

/**
 * Outcome of a verification attempt, together with the number of tries the user has left.
 */
readonly class EmailVerificationResult
{
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_INVALID = 'otp_invalid';
    public const STATUS_EXPIRED = 'otp_expired';
    public const STATUS_MISSING = 'otp_missing';
    public const STATUS_LOCKED = 'otp_locked';

    public function __construct(
        public string $status,
        public int    $attemptsLeft = 0,
        public int    $lockedForMinutes = 0,
    )
    {
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }
}
