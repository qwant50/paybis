<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * A single grid-aligned price observation: the price at a point in time.
 *
 * It carries a 5-minute candle's *open* price and *open time*. A candle's open
 * price is final the instant the candle opens — it never changes, even while the
 * candle is still forming — so the point is immutable history and its time sits
 * exactly on the 5-minute UTC grid. This is what lets {@see RateFetcher} stamp
 * samples with an aligned, stable `recordedAt` instead of the jittered wall-clock
 * instant of the fetch.
 */
final readonly class PricePoint
{
    public function __construct(
        /** The price as a decimal string (full precision preserved). */
        public string $price,
        /** The observation time: UTC, aligned to the 5-minute grid (seconds = 00). */
        public \DateTimeImmutable $time,
    ) {
    }
}
