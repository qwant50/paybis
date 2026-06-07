<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * Outcome of a single {@see RateFetcher::fetchAll()} run.
 */
final readonly class RateFetchReport
{
    public function __construct(
        public int $fetched,
        public int $failed,
    ) {
    }

    public function total(): int
    {
        return $this->fetched + $this->failed;
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
