<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Service;

use App\Application\Service\PriceHistoryProvider;
use App\Application\Service\PricePoint;
use App\Application\Service\RateFetcher;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RateFetcherTest extends Unit
{
    public function testItStoresEveryPointOfEveryPairStampedWithItsOpenTime(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));
        $prices = ['BTCEUR' => ['52000.00', '52878.09'], 'ETHEUR' => ['1300.00', '1357.96'], 'LTCEUR' => ['36.00', '36.87']];

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturnCallback(
            static fn (string $symbol): array => [
                new PricePoint($prices[$symbol][0], $t0),
                new PricePoint($prices[$symbol][1], $t1),
            ],
        );

        $saved = [];
        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(6))
            ->method('save')
            ->willReturnCallback(static function (ExchangeRate $rate) use (&$saved): bool {
                $saved[] = [$rate->pair->value(), $rate->rate->asString(), $rate->recordedAt];

                return true;
            });

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        $this->assertSame(6, $report->stored);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(0, $report->failed);
        $this->assertFalse($report->hasFailures());

        $this->assertSame(
            ['EUR/BTC', '52000.000000000000', $t0],
            $saved[0],
        );
        $this->assertSame(
            ['EUR/BTC', '52878.090000000000', $t1],
            $saved[1],
        );
    }

    public function testItCountsAlreadyStoredSlotsAsSkipped(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturn([
            new PricePoint('100.00', $t0),
            new PricePoint('101.00', $t1),
        ]);

        // First point of each pair already exists (skip), second is new (stored).
        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturnCallback(
            static fn (ExchangeRate $rate): bool => $rate->recordedAt != $t0,
        );

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        $this->assertSame(3, $report->stored);  // one new point per pair
        $this->assertSame(3, $report->skipped); // one existing point per pair
        $this->assertSame(0, $report->failed);
    }

    public function testItIsolatesAFailingPair(): void
    {
        $time = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturnCallback(
            static function (string $symbol) use ($time): array {
                if ($symbol === 'ETHEUR') {
                    throw new \RuntimeException('Binance down for ETH');
                }

                return [new PricePoint('100.00', $time)];
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(2))->method('save')->willReturn(true);

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        $this->assertSame(2, $report->stored);
        $this->assertSame(1, $report->failed); // the ETH pair fetch failed once
        $this->assertTrue($report->hasFailures());
    }

    public function testItIsolatesASinglePrecisionLosingPoint(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturn([
            new PricePoint('1.0000000000001', $t0), // 13 decimals -> PrecisionLossException
            new PricePoint('2.00', $t1),
        ]);

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturn(true);

        $report = (new RateFetcher($binance, $repository, $this->createMock(LoggerInterface::class)))->fetchAll();

        // Per pair: the bad point fails, the good one stores. 3 pairs.
        $this->assertSame(3, $report->stored);
        $this->assertSame(3, $report->failed);
        $this->assertTrue($report->hasFailures());
    }
}
