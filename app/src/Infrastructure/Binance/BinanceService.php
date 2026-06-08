<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use App\Application\Service\PriceHistoryProvider;
use App\Application\Service\PricePoint;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\Model\Interval;
use Binance\Common\ApiException;

/**
 * Thin wrapper over the Binance spot REST client, exposing only the market data
 * this application needs: a window of recent grid-aligned price points.
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

    /**
     * How many of the most recent candles to fetch each run. This is the backfill
     * window: re-affirming the last {@see self::BACKFILL_CANDLES} 5-minute slots lets
     * a run that follows downtime fill the slots it missed (already-stored slots are
     * idempotent no-ops). 12 candles ≈ 1 hour of recovery.
     */
    private const int BACKFILL_CANDLES = 12;

    public function __construct(
        private SpotRestApi $spotRestApi,
    ) {
    }

    /**
     * Recent grid-aligned price points for a Binance symbol (e.g. "BTCEUR"),
     * ascending by time, including the current (still-forming) slot.
     *
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when the request fails or yields no usable points
     */
    public function recentPricePoints(string $symbol): array
    {
        try {
            $response = $this->spotRestApi->klines($symbol, self::INTERVAL, limit: self::BACKFILL_CANDLES);
        } catch (ApiException $e) {
            throw new \RuntimeException(
                sprintf('Binance klines request for "%s" failed: %s', $symbol, $e->getMessage()),
                0,
                $e,
            );
        }

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

        if ($points === []) {
            throw new \RuntimeException(sprintf('Binance returned no usable price points for symbol "%s".', $symbol));
        }

        return $points;
    }
}
