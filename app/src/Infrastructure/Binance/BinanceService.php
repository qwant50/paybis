<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use App\Application\Service\ClosedCandle;
use App\Application\Service\ClosedCandleProvider;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\Model\Interval;
use Binance\Common\ApiException;

/**
 * Thin wrapper over the Binance spot REST client, exposing only the single piece
 * of market data this application needs: the latest *closed* 5-minute candle.
 *
 * A closed candle gives a price that is final (immutable history) and an open
 * time that sits exactly on the 5-minute grid, so the stored sample is aligned
 * without flooring the local clock. The {@see self::INTERVAL} here must match the
 * scheduler cadence in {@see \App\Infrastructure\Scheduler\RatesSchedule}.
 */
final readonly class BinanceService implements ClosedCandleProvider
{
    private const Interval INTERVAL = Interval::INTERVAL_5M;

    /**
     * How many of the most recent candles to fetch. Binance always returns the
     * still-forming candle as the last element, so at least 2 are needed to see a
     * closed one. We ask for 3: the extra candle is a buffer so a fetch landing
     * exactly on a 5-minute boundary (where the freshest just-closed candle's
     * close time can momentarily read as not-yet-passed under clock skew) still
     * finds an unambiguously closed candle instead of throwing.
     */
    private const int CANDLE_FETCH_LIMIT = 3;

    public function __construct(
        private SpotRestApi $spotRestApi,
    ) {
    }

    /**
     * The latest closed 5-minute candle for a Binance symbol (e.g. "BTCEUR").
     *
     * Binance returns the candles in ascending order with the last element being
     * the still-forming (not yet closed) candle, whose price keeps changing — so
     * it is deliberately filtered out: we keep the most recent candle whose close
     * time has already passed. Prices and timestamps are kept as strings/ints to
     * preserve precision.
     *
     * @throws \RuntimeException when the request fails or no closed candle is available
     */
    public function latestClosedCandle(string $symbol): ClosedCandle
    {
        try {
            $response = $this->spotRestApi->klines($symbol, self::INTERVAL, limit: self::CANDLE_FETCH_LIMIT);
        } catch (ApiException $e) {
            throw new \RuntimeException(
                sprintf('Binance klines request for "%s" failed: %s', $symbol, $e->getMessage()),
                0,
                $e,
            );
        }

        // Each candle is a raw row: [0]=openTime(ms), …, [4]=close, …, [6]=closeTime(ms).
        $nowMs = (int) (microtime(true) * 1000);
        $closed = null;
        foreach ($response->getData()->getItems() as $candle) {
            $row = $candle->getItems();
            if (count($row) < 7) {
                continue;
            }
            if ((int) $row[6] < $nowMs) {
                $closed = $row; // rows are ascending by open time → keep the most recent closed one
            }
        }

        if ($closed === null) {
            throw new \RuntimeException(sprintf('Binance returned no closed candle for symbol "%s".', $symbol));
        }

        $closePrice = (string) $closed[4];
        if ($closePrice === '') {
            throw new \RuntimeException(sprintf('Binance returned an empty close price for symbol "%s".', $symbol));
        }

        // The `@` epoch format is always UTC; a 5-minute open time is whole-second
        // and on the grid, so seconds land on :00.
        $openTime = new \DateTimeImmutable('@' . intdiv((int) $closed[0], 1000))
            ->setTimezone(new \DateTimeZone('UTC'));

        return new ClosedCandle($closePrice, $openTime);
    }
}
