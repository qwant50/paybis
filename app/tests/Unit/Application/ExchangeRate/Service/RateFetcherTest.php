<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ExchangeRate\Service;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Application\ExchangeRate\Service\RateFetcher;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class RateFetcherTest extends Unit
{
    private const string NOW = '2026-03-15 12:00:00';

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

        $report = $this->fetcher($binance, $repository)->fetchAll();

        $this->assertSame(6, $report->stored);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(0, $report->failed);
        $this->assertFalse($report->hasFailures());

        $this->assertSame(['EUR/BTC', '52000.000000000000', $t0], $saved[0]);
        $this->assertSame(['EUR/BTC', '52878.090000000000', $t1], $saved[1]);
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

        $report = $this->fetcher($binance, $repository)->fetchAll();

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

        $report = $this->fetcher($binance, $repository)->fetchAll();

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

        $report = $this->fetcher($binance, $repository)->fetchAll();

        // Per pair: the bad point fails, the good one stores. 3 pairs.
        $this->assertSame(3, $report->stored);
        $this->assertSame(3, $report->failed);
        $this->assertTrue($report->hasFailures());
    }

    public function testItEmitsFetchAndLatencyMetrics(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturn([new PricePoint('100.00', $t0)]);

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturn(true);

        /** @var list<array{string, int, array<string, string>}> $counters */
        $counters = [];
        /** @var list<string> $timings */
        $timings = [];
        $metrics = $this->createMock(Metrics::class);
        $metrics->method('increment')->willReturnCallback(
            static function (string $name, int $value = 1, array $tags = []) use (&$counters): void {
                $counters[] = [$name, $value, $tags];
            },
        );
        $metrics->method('timing')->willReturnCallback(
            static function (string $name) use (&$timings): void {
                $timings[] = $name;
            },
        );

        $this->fetcher($binance, $repository, $metrics)->fetchAll();

        // One success counter per pair (3 pairs).
        $this->assertCount(3, array_filter(
            $counters,
            static fn (array $c): bool => $c[0] === 'binance.fetch' && ($c[2]['outcome'] ?? null) === 'success',
        ));

        // One Binance latency timing per pair, plus the run duration.
        $this->assertCount(3, array_filter($timings, static fn (string $n): bool => $n === 'binance.fetch.duration_ms'));
        $this->assertContains('rate_fetch.duration_ms', $timings);

        // Run-total counters; stored carries 1 point x 3 pairs.
        $names = array_map(static fn (array $c): string => $c[0], $counters);
        $this->assertContains('rate_fetch.skipped', $names);
        $this->assertContains('rate_fetch.failed', $names);
        $stored = array_values(array_filter($counters, static fn (array $c): bool => $c[0] === 'rate_fetch.stored'));
        $this->assertSame(3, $stored[0][1]);
    }

    public function testInSteadyStateItRequestsOnlyTheMinimumWindow(): void
    {
        // Latest stored slot is "now": the trailing gap is ~0, so the window floors
        // at the 12-candle (~1h) re-affirm — unchanged from the old fixed behaviour.
        $limits = $this->captureLimits(latestRecordedAt: new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        $this->assertSame([12, 12, 12], $limits);
    }

    public function testItWidensTheWindowToCoverATrailingGap(): void
    {
        // 3h behind -> ceil(180min / 5) = 36 slots, + 2 overlap = 38.
        $limits = $this->captureLimits(
            latestRecordedAt: (new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')))->modify('-3 hours'),
        );

        $this->assertSame([38, 38, 38], $limits);
    }

    public function testItBootstrapsTheWidestWindowWhenNoSampleIsStored(): void
    {
        $limits = $this->captureLimits(latestRecordedAt: null);

        $this->assertSame([1000, 1000, 1000], $limits);
    }

    public function testItClosesAGapBeyondOneRequestViaPagedRangeFetch(): void
    {
        // 10 days behind: far more than 1000 candles fit in one request, so the
        // fetcher must switch to the paging range fetch and close the whole gap in
        // this run — fetching only the most-recent 1000 would leave a permanent
        // interior hole that nothing automatic repairs.
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $latest = $now->modify('-10 days');

        $ranges = [];
        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->never())->method('recentPricePoints');
        $binance->method('pricePointsBetween')->willReturnCallback(
            static function (string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$ranges, $now): array {
                $ranges[] = [$symbol, $from, $to];

                return [new PricePoint('100.00', $now)];
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->method('latestRecordedAt')->willReturn($latest);
        $repository->method('save')->willReturn(true);

        $gapCounters = 0;
        $metrics = $this->createMock(Metrics::class);
        $metrics->method('increment')->willReturnCallback(
            static function (string $name) use (&$gapCounters): void {
                if ($name === 'rate_fetch.gap_exceeds_window') {
                    ++$gapCounters;
                }
            },
        );

        $report = $this->fetcher($binance, $repository, $metrics)->fetchAll();

        $this->assertSame(3, $report->stored);
        $this->assertSame(3, $gapCounters); // the oversized gap is still surfaced, one per pair

        // The range anchors at the latest stored slot (re-affirmed idempotently) and
        // reaches one slot past "now" so the still-forming candle is included even
        // when "now" sits exactly on the grid.
        $this->assertCount(3, $ranges);
        $this->assertEquals(['BTCEUR', $latest, $now->modify('+300 seconds')], $ranges[0]);
    }

    /**
     * Drive a fetch with the given latest-stored time and capture the per-pair
     * `limit` passed to the provider.
     *
     * @return list<int>
     */
    private function captureLimits(?\DateTimeImmutable $latestRecordedAt, ?Metrics $metrics = null): array
    {
        $t0 = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));

        $limits = [];
        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('recentPricePoints')->willReturnCallback(
            static function (string $symbol, int $limit) use (&$limits, $t0): array {
                $limits[] = $limit;

                return [new PricePoint('100.00', $t0)];
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->method('latestRecordedAt')->willReturn($latestRecordedAt);
        $repository->method('save')->willReturn(true);

        $this->fetcher($binance, $repository, $metrics, new MockClock($t0))->fetchAll();

        return $limits;
    }

    private function fetcher(
        PriceHistoryProvider $binance,
        RateRepository $repository,
        ?Metrics $metrics = null,
        ?ClockInterface $clock = null,
    ): RateFetcher {
        $logger = $this->createMock(LoggerInterface::class);

        return new RateFetcher(
            $binance,
            $repository,
            new PricePointPersister($repository, $logger, $this->createMock(Metrics::class)),
            $logger,
            $metrics ?? $this->createMock(Metrics::class),
            $clock ?? new MockClock(new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'))),
        );
    }
}
