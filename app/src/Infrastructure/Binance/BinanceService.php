<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use App\Application\Service\TickerPriceProvider;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Binance\Common\ApiException;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper over the Binance spot REST client, exposing only the single
 * piece of market data this application needs: the latest symbol price.
 */
final class BinanceService implements TickerPriceProvider
{
    private SpotRestApi $spotRestApi;

    public function __construct(
        string $apiKey,
        string $secretKey,
        bool $testnet = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
        try {
            $builder = SpotRestApiUtil::getConfigurationBuilder();
            $builder->apiKey($apiKey)->secretKey($secretKey);

            if ($testnet) {
                $builder->url('https://testnet.binance.vision');
            }

            $this->spotRestApi = new SpotRestApi($builder->build());
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to initialise Binance API client.', ['exception' => $e]);

            throw new \RuntimeException('Failed to initialise Binance API client: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Latest price for a Binance symbol (e.g. "BTCEUR"), returned as a decimal
     * string to preserve full precision.
     *
     * @throws \RuntimeException when the request fails or no price is returned
     */
    public function getTickerPrice(string $symbol): string
    {
        try {
            $response = $this->spotRestApi->tickerPrice($symbol);
        } catch (ApiException $e) {
            throw new \RuntimeException(
                sprintf('Binance ticker request for "%s" failed: %s', $symbol, $e->getMessage()),
                0,
                $e,
            );
        }

        // A single-symbol query resolves to the TickerPriceResponse1 variant of
        // the oneOf response, which carries the {symbol, price} pair.
        $price = $response->getData()->getTickerPriceResponse1()?->getPrice();

        if ($price === null || $price === '') {
            throw new \RuntimeException(sprintf('Binance returned no price for symbol "%s".', $symbol));
        }

        return (string) $price;
    }
}
