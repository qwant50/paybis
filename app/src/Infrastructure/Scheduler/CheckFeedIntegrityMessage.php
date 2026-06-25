<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

/**
 * Marker message dispatched by the scheduler every hour to trigger a feed
 * integrity check (detect and repair missing 5-minute slots in the trailing 24h).
 * Carries no payload — the work is always "check all supported pairs".
 */
final readonly class CheckFeedIntegrityMessage
{
}
