<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * Provides the latest price for a Binance symbol. Abstraction over the concrete
 * Binance client so consumers (and tests) depend on behaviour, not the SDK.
 */
interface TickerPriceProvider
{
    /**
     * Latest price for a Binance symbol (e.g. "BTCEUR") as a decimal string.
     *
     * @throws \RuntimeException when the request fails or no price is returned
     */
    public function getTickerPrice(string $symbol): string;
}
