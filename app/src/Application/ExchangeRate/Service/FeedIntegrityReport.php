<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

/**
 * Outcome of a single {@see RateFeedIntegrityChecker::check()} run, totalled
 * across all pairs.
 */
final readonly class FeedIntegrityReport
{
    public function __construct(
        /** Pairs whose check completed (including ones skipped for having no samples). */
        public int $checkedPairs,
        /** Pairs whose check aborted on an exception. */
        public int $failedPairs,
        /** Missing slots detected, before the repair pass. */
        public int $missingSlots,
        /** Missing slots the repair pass filled. */
        public int $repairedSlots,
        /** Missing slots still absent after repair (e.g. the upstream has no candle). */
        public int $unrepairedSlots,
    ) {
    }

    /** True when anything needs operator attention: a failed pair or an unrepairable hole. */
    public function hasFailures(): bool
    {
        return $this->failedPairs > 0 || $this->unrepairedSlots > 0;
    }
}
