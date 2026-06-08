<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * The finalized 5-minute candle a {@see ClosedCandleProvider} returns: the close
 * price and the candle's open time.
 *
 * Because it represents a *closed* candle, both values are immutable history — the
 * price will never change and the open time sits exactly on the 5-minute UTC grid.
 * This is what lets {@see RateFetcher} stamp samples with an aligned, stable
 * `recordedAt` instead of the jittered wall-clock instant of the fetch.
 */
final readonly class ClosedCandle
{
    public function __construct(
        /** The candle close as a decimal string (full precision preserved). */
        public string $closePrice,
        /** The candle open time: UTC, aligned to the 5-minute grid (seconds = 00). */
        public \DateTimeImmutable $openTime,
    ) {
    }
}
