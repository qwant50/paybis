<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * Provides the latest *closed* 5-minute candle for a Binance symbol. Abstraction
 * over the concrete Binance client so consumers (and tests) depend on behaviour,
 * not the SDK.
 *
 * The closed candle carries an authoritative, grid-aligned open time, so the
 * stored sample's `recordedAt` comes from the exchange rather than the local
 * clock (see {@see ClosedCandle}).
 */
interface ClosedCandleProvider
{
    /**
     * The latest closed 5-minute candle for a Binance symbol (e.g. "BTCEUR").
     *
     * @throws \RuntimeException when the request fails or no closed candle is available
     */
    public function latestClosedCandle(string $symbol): ClosedCandle;
}
