<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ExchangeRate\Service;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Application\ExchangeRate\Service\RateBackfiller;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\RateRepository;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RateBackfillerTest extends Unit
{
    private \DateTimeImmutable $from;
    private \DateTimeImmutable $to;

    protected function _before(): void
    {
        $this->from = new \DateTimeImmutable('2026-03-15 00:00:00', new \DateTimeZone('UTC'));
        $this->to = new \DateTimeImmutable('2026-03-16 00:00:00', new \DateTimeZone('UTC'));
    }

    public function testItBackfillsEveryPairOverTheRange(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 09:00:00', new \DateTimeZone('UTC'));
        $t1 = new \DateTimeImmutable('2026-03-15 09:05:00', new \DateTimeZone('UTC'));

        $symbols = [];
        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('pricePointsBetween')->willReturnCallback(
            static function (string $symbol) use (&$symbols, $t0, $t1): array {
                $symbols[] = $symbol;

                return [new PricePoint('100.00', $t0), new PricePoint('101.00', $t1)];
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(6))->method('save')->willReturn(true); // 2 points x 3 pairs

        $report = $this->backfiller($binance, $repository)->backfill($this->from, $this->to);

        $this->assertSame(6, $report->stored);
        $this->assertSame(0, $report->failed);
        $this->assertEqualsCanonicalizing(['BTCEUR', 'ETHEUR', 'LTCEUR'], $symbols);
    }

    public function testItIsolatesAFailingPair(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 09:00:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->method('pricePointsBetween')->willReturnCallback(
            static function (string $symbol) use ($t0): array {
                if ($symbol === 'ETHEUR') {
                    throw new \RuntimeException('Binance down for ETH');
                }

                return [new PricePoint('100.00', $t0)];
            },
        );

        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->exactly(2))->method('save')->willReturn(true);

        $report = $this->backfiller($binance, $repository)->backfill($this->from, $this->to);

        $this->assertSame(2, $report->stored);
        $this->assertSame(1, $report->failed);
        $this->assertTrue($report->hasFailures());
    }

    public function testItRestrictsToASinglePairWhenAsked(): void
    {
        $t0 = new \DateTimeImmutable('2026-03-15 09:00:00', new \DateTimeZone('UTC'));

        $binance = $this->createMock(PriceHistoryProvider::class);
        $binance->expects($this->once())
            ->method('pricePointsBetween')
            ->with('BTCEUR', $this->from, $this->to)
            ->willReturn([new PricePoint('100.00', $t0)]);

        $repository = $this->createMock(RateRepository::class);
        $repository->expects($this->once())->method('save')->willReturn(true);

        $report = $this->backfiller($binance, $repository)
            ->backfill($this->from, $this->to, CurrencyPair::fromString('EUR/BTC'));

        $this->assertSame(1, $report->stored);
    }

    private function backfiller(PriceHistoryProvider $binance, RateRepository $repository): RateBackfiller
    {
        $logger = $this->createMock(LoggerInterface::class);

        return new RateBackfiller(
            $binance,
            new PricePointPersister($repository, $logger, $this->createMock(Metrics::class)),
            $logger,
            $this->createMock(Metrics::class),
        );
    }
}
