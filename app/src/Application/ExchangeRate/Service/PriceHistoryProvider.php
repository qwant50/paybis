<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

/**
 * Provides recent grid-aligned price points for a Binance symbol. Abstraction over
 * the concrete Binance client so consumers (and tests) depend on behaviour, not the
 * SDK.
 *
 * Each point carries an authoritative, grid-aligned open time, so stored samples'
 * `recordedAt` come from the exchange rather than the local clock (see
 * {@see PricePoint}). Returning a window of recent points (not just the latest)
 * lets the fetcher backfill any 5-minute slots missed during downtime.
 */
interface PriceHistoryProvider
{
    /**
     * Recent grid-aligned price points for a Binance symbol (e.g. "BTCEUR"),
     * ascending by time. Includes the current (still-forming) slot — a candle's
     * open price is immutable from the moment it opens.
     *
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when the request fails or yields no usable points
     */
    public function recentPricePoints(string $symbol): array;
}
