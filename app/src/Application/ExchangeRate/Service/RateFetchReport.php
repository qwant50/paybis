<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

/**
 * Outcome of a single {@see RateFetcher::fetchAll()} run, counted at price-point
 * granularity across all pairs.
 */
final readonly class RateFetchReport
{
    public function __construct(
        /** New slots inserted. */
        public int $stored,
        /** Slots already present — idempotent no-ops (backfill overlap). */
        public int $skipped,
        /** Pair-fetch errors plus per-point errors. */
        public int $failed,
    ) {
    }

    public function total(): int
    {
        return $this->stored + $this->skipped + $this->failed;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
