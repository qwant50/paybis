<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\Model\Interval;
use Binance\Client\Spot\Model\KlinesResponse;
use Binance\Common\ApiException;
use Binance\Common\Dtos\ApiResponse;

/**
 * Thin wrapper over the Binance spot REST client, exposing only the market data
 * this application needs: grid-aligned price points.
 *
 * Each point is a 5-minute candle's *open* price stamped with its *open time*. A
 * candle's open price is final the moment the candle opens — it never changes, even
 * while the candle is still forming — so every returned candle yields an immutable,
 * grid-aligned point and no "is it closed?" filtering is needed. The {@see self::INTERVAL}
 * here must match the scheduler cadence in {@see \App\Infrastructure\Scheduler\RatesSchedule}.
 */
final readonly class BinanceService implements PriceHistoryProvider
{
    private const Interval INTERVAL = Interval::INTERVAL_5M;

    /** The {@see self::INTERVAL} candle length in milliseconds; used to page time ranges. */
    private const int INTERVAL_MS = 5 * 60 * 1000;

    /**
     * The most candles Binance returns from a single `klines` request. Both fetch
     * methods clamp to this; {@see self::pricePointsBetween()} pages across it to
     * cover wider ranges. (Binance's documented hard cap is 1000.)
     */
    private const int MAX_CANDLES = 1000;

    public function __construct(
        private SpotRestApi $spotRestApi,
    ) {
    }

    /**
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when the request fails or yields no usable points
     */
    public function recentPricePoints(string $symbol, int $limit): array
    {
        $response = $this->klines($symbol, null, null, min($limit, self::MAX_CANDLES));

        $points = $this->toPoints($response);

        if ($points === []) {
            throw new \RuntimeException(sprintf('Binance returned no usable price points for symbol "%s".', $symbol));
        }

        return $points;
    }

    /**
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when a request fails
     */
    public function pricePointsBetween(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $startMs = $from->getTimestamp() * 1000;
        $endMs = $to->getTimestamp() * 1000;

        $points = [];
        while ($startMs < $endMs) {
            $page = $this->toPoints($this->klines($symbol, $startMs, $endMs - 1, self::MAX_CANDLES));
            if ($page === []) {
                break;
            }

            foreach ($page as $point) {
                if ($point->time < $to) {
                    $points[] = $point;
                }
            }

            // Advance past the last candle of this page. The page is ascending, so
            // its last open time is the newest we've seen; the guard below stops us
            // if Binance ever fails to advance (avoids an infinite loop).
            $lastOpenMs = end($page)->time->getTimestamp() * 1000;
            $nextStartMs = $lastOpenMs + self::INTERVAL_MS;
            if (count($page) < self::MAX_CANDLES || $nextStartMs <= $startMs) {
                break;
            }

            $startMs = $nextStartMs;
        }

        return $points;
    }

    /**
     * HTTP statuses Binance answers with when rate limiting: 429 = request rate
     * exceeded, 418 = IP auto-banned for ignoring 429s.
     */
    private const array RATE_LIMIT_STATUSES = [429, 418];

    /**
     * @return ApiResponse<KlinesResponse>
     *
     * @throws RateLimitException when Binance rate-limits the request
     * @throws \RuntimeException  when the underlying request fails any other way
     */
    private function klines(string $symbol, ?int $startTimeMs, ?int $endTimeMs, int $limit): ApiResponse
    {
        try {
            return $this->spotRestApi->klines($symbol, self::INTERVAL, $startTimeMs, $endTimeMs, null, $limit);
        } catch (ApiException $e) {
            if (in_array($e->getCode(), self::RATE_LIMIT_STATUSES, true)) {
                throw new RateLimitException(
                    sprintf('Binance rate-limited the klines request for "%s" (HTTP %d): %s', $symbol, $e->getCode(), $e->getMessage()),
                    $e->getCode(),
                    $e,
                );
            }

            throw new \RuntimeException(
                sprintf('Binance klines request for "%s" failed: %s', $symbol, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Map a klines response to grid-aligned points, skipping malformed rows.
     *
     * @param ApiResponse<KlinesResponse> $response
     *
     * @return list<PricePoint>
     */
    private function toPoints(ApiResponse $response): array
    {
        // Each candle is a raw row: [0]=openTime(ms), [1]=open, …, [4]=close, …
        $points = [];
        foreach ($response->getData()->getItems() as $candle) {
            $row = $candle->getItems();
            if (count($row) < 2) {
                continue;
            }

            $openPrice = (string) $row[1];
            if ($openPrice === '') {
                continue;
            }

            // The `@` epoch format is always UTC; a 5-minute open time is whole-second
            // and on the grid, so seconds land on :00.
            $openTime = new \DateTimeImmutable('@' . intdiv((int) $row[0], 1000))
                ->setTimezone(new \DateTimeZone('UTC'));

            $points[] = new PricePoint($openPrice, $openTime);
        }

        return $points;
    }
}
