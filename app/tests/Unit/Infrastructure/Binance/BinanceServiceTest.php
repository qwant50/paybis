<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Binance;

use App\Infrastructure\Binance\BinanceService;
use App\Infrastructure\Binance\RateLimitException;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\Model\KlinesItem;
use Binance\Client\Spot\Model\KlinesResponse;
use Binance\Common\ApiException;
use Binance\Common\Dtos\ApiResponse;
use Codeception\Test\Unit;

final class BinanceServiceTest extends Unit
{
    private const string SYMBOL = 'BTCEUR';
    private const int STEP_MS = 300_000; // 5 minutes
    private const int MAX_CANDLES = 1000;

    public function testItMapsEveryCandleToAnOpenPricePointIncludingTheFormingOne(): void
    {
        $gridOpen = (new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp() * 1000;

        // Ascending by open time; the last candle is still forming. Its open price
        // is already final, so it is kept like the rest.
        $service = $this->serviceReturning([
            self::row($gridOpen, '52000.00'),
            self::row($gridOpen + self::STEP_MS, '52878.09'),
            self::row($gridOpen + 2 * self::STEP_MS, '52999.99'),
        ]);

        $points = $service->recentPricePoints(self::SYMBOL, 12);

        $this->assertCount(3, $points);
        $this->assertSame(['52000.00', '52878.09', '52999.99'], array_map(static fn ($p) => $p->price, $points));
        $this->assertEquals(
            new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')),
            $points[0]->time,
        );
        // Open times are whole-second, on the 5-minute grid.
        foreach ($points as $point) {
            $this->assertSame('00', $point->time->format('s'));
        }
    }

    public function testItUsesTheOpenPriceNotTheClosePrice(): void
    {
        // open = 100.00 (column 1), close = 999.99 (column 4): we must read the open.
        $service = $this->serviceReturning([self::row(1_700_000_000_000, '100.00', '999.99')]);

        $points = $service->recentPricePoints(self::SYMBOL, 12);

        $this->assertSame('100.00', $points[0]->price);
    }

    public function testItSkipsMalformedRowsButKeepsValidOnes(): void
    {
        $service = $this->serviceReturning([
            new KlinesItem(['1700000000000']), // too few columns
            self::row(1_700_000_300_000, ''),  // empty open price
            self::row(1_700_000_600_000, '49750.00'),
        ]);

        $points = $service->recentPricePoints(self::SYMBOL, 12);

        $this->assertCount(1, $points);
        $this->assertSame('49750.00', $points[0]->price);
    }

    public function testItThrowsWhenNoUsablePointIsAvailable(): void
    {
        $service = $this->serviceReturning([
            new KlinesItem(['1700000000000']),
            self::row(1_700_000_300_000, ''),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no usable price points/i');

        $service->recentPricePoints(self::SYMBOL, 12);
    }

    public function testItWrapsApiExceptionsWithTheSymbol(): void
    {
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willThrowException(new ApiException('boom'));
        $service = new BinanceService($api);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BTCEUR/');

        $service->recentPricePoints(self::SYMBOL, 12);
    }

    public function testItThrowsADistinctRateLimitExceptionOn429And418(): void
    {
        // 429 = rate limited, 418 = IP auto-banned for ignoring 429s. Both must
        // surface as RateLimitException so the retry decorator can refuse to
        // hammer the exchange while it is asking for less load.
        foreach ([429, 418] as $status) {
            $api = $this->createMock(SpotRestApi::class);
            $api->method('klines')->willThrowException(new ApiException('rate limited', $status));
            $service = new BinanceService($api);

            try {
                $service->recentPricePoints(self::SYMBOL, 12);
                $this->fail(sprintf('Expected RateLimitException for HTTP %d.', $status));
            } catch (RateLimitException $e) {
                $this->assertStringContainsString(self::SYMBOL, $e->getMessage());
            }
        }
    }

    public function testItForwardsTheRequestedLimitToKlinesClampedToTheMaximum(): void
    {
        $captured = [];
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willReturnCallback(
            function ($symbol, $interval, $startTime, $endTime, $timeZone, $limit) use (&$captured): ApiResponse {
                $captured[] = $limit;

                return new ApiResponse(200, [], new KlinesResponse([self::row(1_700_000_000_000, '1.00')]), []);
            },
        );
        $service = new BinanceService($api);

        $service->recentPricePoints(self::SYMBOL, 38);
        $service->recentPricePoints(self::SYMBOL, 5000); // beyond the cap

        $this->assertSame([38, self::MAX_CANDLES], $captured);
    }

    public function testPricePointsBetweenReturnsTheRangeAndExcludesTheUpperBound(): void
    {
        $from = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-03-15 12:15:00', new \DateTimeZone('UTC')); // exclusive
        $fromMs = $from->getTimestamp() * 1000;

        // Four candles at 12:00/05/10/15; the 12:15 one is at the exclusive bound.
        $service = $this->serviceReturning([
            self::row($fromMs, '100.00'),
            self::row($fromMs + self::STEP_MS, '101.00'),
            self::row($fromMs + 2 * self::STEP_MS, '102.00'),
            self::row($fromMs + 3 * self::STEP_MS, '103.00'), // 12:15 -> dropped (>= to)
        ]);

        $points = $service->pricePointsBetween(self::SYMBOL, $from, $to);

        $this->assertSame(['100.00', '101.00', '102.00'], array_map(static fn ($p) => $p->price, $points));
    }

    public function testPricePointsBetweenPagesUntilTheRangeIsCovered(): void
    {
        $from = new \DateTimeImmutable('2026-03-15 00:00:00', new \DateTimeZone('UTC'));
        $fromMs = $from->getTimestamp() * 1000;

        // A full first page (1000 candles) forces a second request; the short second
        // page (3 candles) terminates the loop.
        $page1Start = $fromMs;
        $page2Start = $fromMs + self::MAX_CANDLES * self::STEP_MS;
        $to = (new \DateTimeImmutable('@' . intdiv($page2Start + 3 * self::STEP_MS, 1000)))->setTimezone(new \DateTimeZone('UTC'));

        $calls = 0;
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willReturnCallback(
            function ($symbol, $interval, $startTime, $endTime, $timeZone, $limit) use (&$calls, $page1Start, $page2Start): ApiResponse {
                ++$calls;
                $items = $startTime <= $page1Start
                    ? self::sequentialRows($page1Start, self::MAX_CANDLES)
                    : self::sequentialRows($page2Start, 3);

                return new ApiResponse(200, [], new KlinesResponse($items), []);
            },
        );
        $service = new BinanceService($api);

        $points = $service->pricePointsBetween(self::SYMBOL, $from, $to);

        $this->assertSame(2, $calls);
        $this->assertCount(self::MAX_CANDLES + 3, $points);
        // Ascending and contiguous across the page boundary.
        $this->assertEquals($from, $points[0]->time);
        $this->assertSame('00', $points[count($points) - 1]->time->format('s'));
    }

    public function testPricePointsBetweenStopsOnAnEmptyPage(): void
    {
        $from = new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-03-15 13:00:00', new \DateTimeZone('UTC'));

        $service = $this->serviceReturning([]); // exchange has nothing for the range

        $this->assertSame([], $service->pricePointsBetween(self::SYMBOL, $from, $to));
    }

    public function testPricePointsBetweenGuardsAgainstANonAdvancingPage(): void
    {
        $from = new \DateTimeImmutable('2026-03-15 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-04-15 00:00:00', new \DateTimeZone('UTC')); // very wide
        $fromMs = $from->getTimestamp() * 1000;

        // A misbehaving exchange that always returns the same full page would loop
        // forever without the advancement guard; assert the loop is bounded.
        $calls = 0;
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willReturnCallback(
            function () use (&$calls, $fromMs): ApiResponse {
                ++$calls;

                return new ApiResponse(200, [], new KlinesResponse(self::sequentialRows($fromMs, self::MAX_CANDLES)), []);
            },
        );
        $service = new BinanceService($api);

        $service->pricePointsBetween(self::SYMBOL, $from, $to);

        $this->assertLessThanOrEqual(2, $calls);
    }

    /**
     * @param list<KlinesItem> $items
     */
    private function serviceReturning(array $items): BinanceService
    {
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willReturn(
            new ApiResponse(200, [], new KlinesResponse($items), []),
        );

        return new BinanceService($api);
    }

    /**
     * @return list<KlinesItem>
     */
    private static function sequentialRows(int $startMs, int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = self::row($startMs + $i * self::STEP_MS, '100.00');
        }

        return $rows;
    }

    /**
     * A raw Binance kline row: [0]=openTime ms, [1]=open, [4]=close, [6]=closeTime ms.
     */
    private static function row(int $openTimeMs, string $open, string $close = '0'): KlinesItem
    {
        return new KlinesItem([
            (string) $openTimeMs, // 0 open time
            $open,                // 1 open
            '0', '0',             // 2 high, 3 low
            $close,               // 4 close
            '0',                  // 5 volume
            (string) ($openTimeMs + self::STEP_MS), // 6 close time
            '0', '0', '0', '0', '0', // 7-11 quote vol, trades, taker buy base/quote, ignore
        ]);
    }
}
