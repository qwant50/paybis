<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

/**
 * Marker message dispatched by the scheduler every 5 minutes to trigger a rate
 * fetch. Carries no payload — the work is always "fetch all supported pairs".
 */
final readonly class FetchRatesMessage
{
}
