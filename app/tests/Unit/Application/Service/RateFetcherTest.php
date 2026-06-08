<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service;

use App\Application\Service\ClosedCandle;
use App\Application\Service\ClosedCandleProvider;
use App\Application\Service\RateFetcher;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RateFetcherTest extends Unit
{
    public function testItFetchesAndStoresEveryPairStampedWithTheCandleOpenTime(): void
    {
        $openTime = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));
        $prices = ['BTCEUR' => '52878.09', 'ETHEUR' => '1357.96', 'LTCEUR' => '36.87'];

        $binance = $this->createMock(ClosedCandleProvider::class);
        $binance->method('latestClosedCandle')->willReturnCallback(
            static fn (string $symbol): ClosedCandle => new ClosedCandle($prices[$symbol], $openTime),
        );

        $saved = [];
        $recordedAt = [];
        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(3))
            ->method('save')
            ->willReturnCallback(static function (ExchangeRate $rate) use (&$saved, &$recordedAt): void {
                $saved[$rate->pair->value()] = $rate->rate->asString();
                $recordedAt[$rate->pair->value()] = $rate->recordedAt;
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

        // Every sample is stamped with the candle's grid-aligned open time.
        foreach ($recordedAt as $when) {
            $this->assertEquals($openTime, $when);
        }
    }

    public function testItIsolatesAFailingPair(): void
    {
        $openTime = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(ClosedCandleProvider::class);
        $binance->method('latestClosedCandle')->willReturnCallback(
            static function (string $symbol) use ($openTime): ClosedCandle {
                if ($symbol === 'ETHEUR') {
                    throw new \RuntimeException('Binance down for ETH');
                }

                return new ClosedCandle('100.00', $openTime);
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
