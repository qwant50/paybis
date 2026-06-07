<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service;

use App\Application\Service\RateFetcher;
use App\Application\Service\TickerPriceProvider;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RateFetcherTest extends Unit
{
    public function testItFetchesAndStoresEveryPair(): void
    {
        $prices = ['BTCEUR' => '52878.09', 'ETHEUR' => '1357.96', 'LTCEUR' => '36.87'];

        $binance = $this->createMock(TickerPriceProvider::class);
        $binance->method('getTickerPrice')->willReturnCallback(
            static fn (string $symbol): string => $prices[$symbol],
        );

        $saved = [];
        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(3))
            ->method('save')
            ->willReturnCallback(static function (ExchangeRate $rate) use (&$saved): void {
                $saved[$rate->pair->value()] = $rate->rate->asString();
            });

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        $this->assertSame(3, $report->fetched);
        $this->assertSame(0, $report->failed);
        $this->assertFalse($report->hasFailures());
        $this->assertSame([
            'EUR/BTC' => '52878.090000000000',
            'EUR/ETH' => '1357.960000000000',
            'EUR/LTC' => '36.870000000000',
        ], $saved);
    }

    public function testItIsolatesAFailingPair(): void
    {
        $binance = $this->createMock(TickerPriceProvider::class);
        $binance->method('getTickerPrice')->willReturnCallback(
            static function (string $symbol): string {
                if ($symbol === 'ETHEUR') {
                    throw new \RuntimeException('Binance down for ETH');
                }

                return '100.00';
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(2))->method('save');

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        $this->assertSame(2, $report->fetched);
        $this->assertSame(1, $report->failed);
        $this->assertTrue($report->hasFailures());
    }
}
