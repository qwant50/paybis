<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ExchangeRate\Service;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Application\ExchangeRate\Service\RateBackfiller;
use App\Application\ExchangeRate\Service\RateFeedIntegrityChecker;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class RateFeedIntegrityCheckerTest extends Unit
{
    private const string NOW = '2026-03-16 12:00:00';

    public function testItRepairsAnInteriorHole(): void
    {
        $t0 = $this->slot('09:00');
        $t1 = $this->slot('09:05');
        $t2 = $this->slot('09:10'); // the hole
        $t3 = $this->slot('09:15');

        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            function (CurrencyPair $pair, \DateTimeImmutable $from) use ($t0, $t1, $t2, $t3): array {
                if ($pair->value() !== 'EUR/BTC') {
                    return []; // other pairs: nothing stored -> skipped with a warning
                }

                // Second call is the post-repair re-check over [t2, t2+5min).
                if ($from->getTimestamp() === $t2->getTimestamp()) {
                    return [$this->exchangeRate($pair, $t2)];
                }

                return [
                    $this->exchangeRate($pair, $t0),
                    $this->exchangeRate($pair, $t1),
                    $this->exchangeRate($pair, $t3),
                ];
            },
        );
        $repository->method('save')->willReturn(true);

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->once())
            ->method('pricePointsBetween')
            ->with('BTCEUR', $t2, $t3) // [first missing, last missing + one slot)
            ->willReturn([new PricePoint('100.00', $t2)]);

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->once())
            ->method('increment')
            ->with('rate_integrity.missing_slots', 1, ['pair' => 'EUR/BTC']);

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(3, $report->checkedPairs);
        $this->assertSame(0, $report->failedPairs);
        $this->assertSame(1, $report->missingSlots);
        $this->assertSame(1, $report->repairedSlots);
        $this->assertSame(0, $report->unrepairedSlots);
        $this->assertFalse($report->hasFailures());
    }

    public function testAContiguousWindowNeedsNoRepair(): void
    {
        $slots = [$this->slot('09:00'), $this->slot('09:05'), $this->slot('09:10')];

        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            fn (CurrencyPair $pair): array => array_map(
                fn (\DateTimeImmutable $slot): ExchangeRate => $this->exchangeRate($pair, $slot),
                $slots,
            ),
        );

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->never())->method('pricePointsBetween');

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->never())->method('increment');

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(3, $report->checkedPairs);
        $this->assertSame(0, $report->missingSlots);
        $this->assertFalse($report->hasFailures());
    }

    public function testItReportsSlotsTheUpstreamCannotSupply(): void
    {
        $t0 = $this->slot('09:00');
        $t1 = $this->slot('09:05'); // the hole — and Binance has no candle for it
        $t2 = $this->slot('09:10');

        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            function (CurrencyPair $pair, \DateTimeImmutable $from) use ($t0, $t1, $t2): array {
                if ($pair->value() !== 'EUR/BTC') {
                    return [];
                }

                // Post-repair re-check over [t1, t1+5min): still nothing stored.
                if ($from->getTimestamp() === $t1->getTimestamp()) {
                    return [];
                }

                return [$this->exchangeRate($pair, $t0), $this->exchangeRate($pair, $t2)];
            },
        );

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->once())
            ->method('pricePointsBetween')
            ->with('BTCEUR', $t1, $t2)
            ->willReturn([]); // upstream has no candle in the range
        // With no points returned, persist() never reaches save(), so no save() stub is needed.

        $increments = [];
        $metrics = $this->createMock(Metrics::class);
        $metrics->method('increment')->willReturnCallback(
            static function (string $name, int $value = 1, array $tags = []) use (&$increments): void {
                $increments[] = [$name, $value, $tags];
            },
        );

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame([
            ['rate_integrity.missing_slots', 1, ['pair' => 'EUR/BTC']],
            ['rate_integrity.unrepaired_slots', 1, ['pair' => 'EUR/BTC']],
        ], $increments);
        $this->assertSame(1, $report->missingSlots);
        $this->assertSame(0, $report->repairedSlots);
        $this->assertSame(1, $report->unrepairedSlots);
        $this->assertTrue($report->hasFailures());
    }

    public function testItRepairsMultipleHolesWithOneRangeFetch(): void
    {
        $t0 = $this->slot('09:00');
        $t1 = $this->slot('09:05'); // hole
        $t2 = $this->slot('09:10');
        $t3 = $this->slot('09:15'); // hole
        $t4 = $this->slot('09:20'); // hole
        $t5 = $this->slot('09:25');

        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            function (CurrencyPair $pair, \DateTimeImmutable $from) use ($t0, $t1, $t2, $t3, $t4, $t5): array {
                if ($pair->value() !== 'EUR/BTC') {
                    return [];
                }

                // Post-repair re-check over [t1, t4+5min): every hole is now filled.
                if ($from->getTimestamp() === $t1->getTimestamp()) {
                    return [
                        $this->exchangeRate($pair, $t1),
                        $this->exchangeRate($pair, $t2), // not a hole — stored all along; the re-check window [t1, t5) spans it
                        $this->exchangeRate($pair, $t3),
                        $this->exchangeRate($pair, $t4),
                    ];
                }

                return [
                    $this->exchangeRate($pair, $t0),
                    $this->exchangeRate($pair, $t2),
                    $this->exchangeRate($pair, $t5),
                ];
            },
        );
        $repository->method('save')->willReturn(true);

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->once())
            ->method('pricePointsBetween')
            ->with('BTCEUR', $t1, $t5) // one fetch spans [first missing, last missing + one slot)
            ->willReturn([
                new PricePoint('100.00', $t1),
                new PricePoint('100.00', $t3),
                new PricePoint('100.00', $t4),
            ]);

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->once())
            ->method('increment')
            ->with('rate_integrity.missing_slots', 3, ['pair' => 'EUR/BTC']);

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(3, $report->checkedPairs);
        $this->assertSame(3, $report->missingSlots);
        $this->assertSame(3, $report->repairedSlots);
        $this->assertSame(0, $report->unrepairedSlots);
        $this->assertFalse($report->hasFailures());
    }

    public function testAnOffGridSampleDoesNotShiftTheExpectedGrid(): void
    {
        // A row that somehow landed off the 5-minute grid (manual SQL, a future
        // bug) must not shift the expected grid — an anchor at 09:02:17 would make
        // every real on-grid slot (09:05, 09:10, …) read as "missing" and fire a
        // mass false repair every hour, forever.
        $offGrid = new \DateTimeImmutable('2026-03-16 09:02:17', new \DateTimeZone('UTC'));
        $slots = [$offGrid, $this->slot('09:05'), $this->slot('09:10')];

        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            fn (CurrencyPair $pair): array => array_map(
                fn (\DateTimeImmutable $slot): ExchangeRate => $this->exchangeRate($pair, $slot),
                $slots,
            ),
        );

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->never())->method('pricePointsBetween');

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->never())->method('increment');

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(0, $report->missingSlots);
        $this->assertFalse($report->hasFailures());
    }

    public function testItSkipsPairsWithNoSamplesInTheWindow(): void
    {
        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturn([]);

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->never())->method('pricePointsBetween');

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->never())->method('increment');

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(3, $report->checkedPairs);
        $this->assertSame(0, $report->failedPairs);
        $this->assertSame(0, $report->missingSlots);
        $this->assertFalse($report->hasFailures());
    }

    public function testItIsolatesAFailingPair(): void
    {
        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturnCallback(
            function (CurrencyPair $pair): array {
                if ($pair->value() === 'EUR/ETH') {
                    throw new \RuntimeException('DB blip');
                }

                return [
                    $this->exchangeRate($pair, $this->slot('09:00')),
                    $this->exchangeRate($pair, $this->slot('09:05')),
                ];
            },
        );

        $binance = $this->createMock(PriceHistoryProvider::class);
        // Holds only because the healthy pairs are contiguous — no repair fires for them.
        $binance->expects($this->never())->method('pricePointsBetween');

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->never())->method('increment');

        $report = $this->checker($repository, $binance, $metrics)->check();

        $this->assertSame(2, $report->checkedPairs);
        $this->assertSame(1, $report->failedPairs);
        $this->assertTrue($report->hasFailures());
    }

    private function checker(
        RateRepository $repository,
        PriceHistoryProvider $binance,
        Metrics $metrics,
    ): RateFeedIntegrityChecker {
        $logger = $this->createMock(LoggerInterface::class);

        // RateBackfiller is final (unmockable); a real instance over the same mocked
        // repository and provider exercises the genuine repair path.
        $backfiller = new RateBackfiller(
            $binance,
            new PricePointPersister($repository, $logger, $this->createMock(Metrics::class)),
            $logger,
            $this->createMock(Metrics::class),
        );

        return new RateFeedIntegrityChecker(
            $repository,
            $backfiller,
            $logger,
            $metrics,
            new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
        );
    }

    private function slot(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-16 ' . $time . ':00', new \DateTimeZone('UTC'));
    }

    private function exchangeRate(CurrencyPair $pair, \DateTimeImmutable $slot): ExchangeRate
    {
        return new ExchangeRate($pair, Rate::fromString('100.00'), $slot);
    }
}
