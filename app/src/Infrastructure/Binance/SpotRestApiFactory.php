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
     * @throws \RuntimeException when the client cannot be initialised
     */
    public function create(string $apiKey, string $secretKey, bool $testnet = false): SpotRestApi
    {
        try {
            $builder = SpotRestApiUtil::getConfigurationBuilder();
            $builder->apiKey($apiKey)->secretKey($secretKey);

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
