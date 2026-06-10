<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

/**
 * Provides grid-aligned price points for a Binance symbol. Abstraction over the
 * concrete Binance client so consumers (and tests) depend on behaviour, not the
 * SDK.
 *
 * Each point carries an authoritative, grid-aligned open time, so stored samples'
 * `recordedAt` come from the exchange rather than the local clock (see
 * {@see PricePoint}). Returning a window of points (not just the latest) lets the
 * fetcher backfill any 5-minute slots missed during downtime.
 */
interface PriceHistoryProvider
{
    /**
     * The up-to-$limit most-recent grid-aligned price points for a Binance symbol
     * (e.g. "BTCEUR"), ascending by time. Includes the current (still-forming)
     * slot — a candle's open price is immutable from the moment it opens.
     *
     * The caller sizes $limit to the trailing gap it needs to close: in steady
     * state a small window re-affirms recent slots, while after downtime a larger
     * window backfills the slots that were missed.
     *
     * @param positive-int $limit how many recent points to request (the adapter
     *                            clamps to the exchange's own maximum)
     *
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when the request fails or yields no usable points
     */
    public function recentPricePoints(string $symbol, int $limit): array;

    /**
     * Every grid-aligned price point for a Binance symbol within the half-open
     * interval [$from, $to), ascending by time. Pages the exchange as needed so an
     * arbitrarily wide range is covered, not just one request's worth.
     *
     * Used to repair arbitrary historical ranges and interior holes; unlike
     * {@see self::recentPricePoints()} it is anchored to an explicit window rather
     * than "now".
     *
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when a request fails
     */
    public function pricePointsBetween(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
