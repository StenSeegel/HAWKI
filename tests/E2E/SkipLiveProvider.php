<?php

declare(strict_types=1);

namespace Tests\E2E;

/**
 * Raised when the live installation cannot supply what an end-to-end test needs
 * - no provider, no key, no database. The test turns it into a skip with the
 * message, so a run outside a configured environment says why it did nothing
 * instead of failing.
 */
final class SkipLiveProvider extends \RuntimeException {}
