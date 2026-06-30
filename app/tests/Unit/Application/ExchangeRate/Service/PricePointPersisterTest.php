<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ExchangeRate\Service;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class PricePointPersisterTest extends Unit
{
    private const string SYMBOL_PAIR = 'EUR/BTC';

    public function testItStoresEachPointStampedWithItsOpenTime(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $saved = [];
        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(2))
            ->method('save')
            ->willReturnCallback(static function (ExchangeRate $rate) use (&$saved): bool {
                $saved[] = [$rate->rate->asString(), $rate->recordedAt];

                return true;
            });

        $counts = $this->persister($repository)->persist(
            CurrencyPair::fromString(self::SYMBOL_PAIR),
            [new PricePoint('52000.00', $t0), new PricePoint('52878.09', $t1)],
        );

        $this->assertSame(['stored' => 2, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame(['52000.000000000000', $t0], $saved[0]);
        $this->assertSame(['52878.090000000000', $t1], $saved[1]);
    }

    public function testItCountsAnAlreadyStoredSlotAsSkipped(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturnCallback(
            static fn (ExchangeRate $rate): bool => $rate->recordedAt != $t0, // t0 already exists
        );

        $counts = $this->persister($repository)->persist(
            CurrencyPair::fromString(self::SYMBOL_PAIR),
            [new PricePoint('100.00', $t0), new PricePoint('101.00', $t1)],
        );

        $this->assertSame(['stored' => 1, 'skipped' => 1, 'failed' => 0], $counts);
    }

    public function testItIsolatesASinglePrecisionLosingPoint(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturn(true);

        $counts = $this->persister($repository)->persist(
            CurrencyPair::fromString(self::SYMBOL_PAIR),
            [
                new PricePoint('1.0000000000001', $t0), // 13 decimals -> PrecisionLossException
                new PricePoint('2.00', $t1),
            ],
        );

        $this->assertSame(['stored' => 1, 'skipped' => 0, 'failed' => 1], $counts);
    }

    public function testItMetersButStillStoresAnAnomalousPriceJump(): void
    {
        // +30% between adjacent 5-minute slots: extreme, but exchange truth — it
        // must be stored (history is immutable) while surfacing a metric so an
        // operator can check whether it is a flash move or corrupt upstream data.
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturn(true);

        $anomalies = [];
        $metrics = $this->createMock(Metrics::class);
        $metrics->method('increment')->willReturnCallback(
            static function (string $name, int $value = 1, array $tags = []) use (&$anomalies): void {
                if ($name === 'rate_anomaly.price_jump') {
                    $anomalies[] = $tags;
                }
            },
        );

        $counts = $this->persister($repository, $metrics)->persist(
            CurrencyPair::fromString(self::SYMBOL_PAIR),
            [new PricePoint('100.00', $t0), new PricePoint('130.00', $t1)],
        );

        $this->assertSame(['stored' => 2, 'skipped' => 0, 'failed' => 0], $counts);
        $this->assertSame([['pair' => self::SYMBOL_PAIR]], $anomalies);
    }

    public function testItDoesNotFlagANormalPriceMove(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $repository = $this->createMock(RateRepository::class);
        $repository->method('save')->willReturn(true);

        $metrics = $this->createMock(Metrics::class);
        $metrics->expects($this->never())->method('increment');

        $this->persister($repository, $metrics)->persist(
            CurrencyPair::fromString(self::SYMBOL_PAIR),
            [new PricePoint('100.00', $t0), new PricePoint('101.00', $t1)],
        );
    }

    private function persister(RateRepository $repository, ?Metrics $metrics = null): PricePointPersister
    {
        return new PricePointPersister(
            $repository,
            $this->createMock(LoggerInterface::class),
            $metrics ?? $this->createMock(Metrics::class),
        );
    }
}
