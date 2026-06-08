<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\SpotRestApiUtil;
use Psr\Log\LoggerInterface;

/**
 * Builds the configured Binance {@see SpotRestApi} client.
 *
 * Extracted from {@see BinanceService} so that the service can receive a ready
 * client and stay unit-testable (the SDK call site can be mocked); this factory
 * is the only place the client is wired together.
 */
final readonly class SpotRestApiFactory
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param int $connectTimeoutSeconds max time to establish the TCP/TLS connection
     * @param int $readTimeoutSeconds    max time to wait for the response
     *
     * @throws \RuntimeException when the client cannot be initialised
     */
    public function create(
        string $apiKey,
        string $secretKey,
        bool $testnet = false,
        int $connectTimeoutSeconds = 3,
        int $readTimeoutSeconds = 8,
    ): SpotRestApi {
        try {
            $builder = SpotRestApiUtil::getConfigurationBuilder();
            $builder->apiKey($apiKey)->secretKey($secretKey);

            // Bound how long a scheduled fetch can hang on a slow/unreachable Binance:
            // without this the SDK's defaults (1000/5000) reach Guzzle as *seconds*
            // (it mislabels them "ms" but applies no conversion), i.e. effectively no
            // timeout. A bounded attempt is also the precondition for the retry in
            // {@see RetryingPriceHistoryProvider}.
            $builder->connectTimeout($connectTimeoutSeconds)->readTimeout($readTimeoutSeconds);

            if ($testnet) {
                $builder->url('https://testnet.binance.vision');
            }

            return new SpotRestApi($builder->build());
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to initialise Binance API client.', ['exception' => $e]);

            throw new \RuntimeException('Failed to initialise Binance API client: ' . $e->getMessage(), 0, $e);
        }
    }
}
