<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Binance;

use App\Infrastructure\Binance\BinanceService;
use Binance\Client\Spot\Api\SpotRestApi;
use Binance\Client\Spot\Model\KlinesItem;
use Binance\Client\Spot\Model\KlinesResponse;
use Binance\Common\ApiException;
use Binance\Common\Dtos\ApiResponse;
use Codeception\Test\Unit;

final class BinanceServiceTest extends Unit
{
    private const string SYMBOL = 'BTCEUR';

    public function testItMapsEveryCandleToAnOpenPricePointIncludingTheFormingOne(): void
    {
        $gridOpen = (new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC')))->getTimestamp() * 1000;

        // Ascending by open time; the last candle is still forming. Its open price
        // is already final, so it is kept like the rest.
        $service = $this->serviceReturning([
            self::row($gridOpen, '52000.00'),
            self::row($gridOpen + 300_000, '52878.09'),
            self::row($gridOpen + 600_000, '52999.99'),
        ]);

        $points = $service->recentPricePoints(self::SYMBOL);

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

        $points = $service->recentPricePoints(self::SYMBOL);

        $this->assertSame('100.00', $points[0]->price);
    }

    public function testItSkipsMalformedRowsButKeepsValidOnes(): void
    {
        $service = $this->serviceReturning([
            new KlinesItem(['1700000000000']), // too few columns
            self::row(1_700_000_300_000, ''),  // empty open price
            self::row(1_700_000_600_000, '49750.00'),
        ]);

        $points = $service->recentPricePoints(self::SYMBOL);

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

        $service->recentPricePoints(self::SYMBOL);
    }

    public function testItWrapsApiExceptionsWithTheSymbol(): void
    {
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willThrowException(new ApiException('boom'));
        $service = new BinanceService($api);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BTCEUR/');

        $service->recentPricePoints(self::SYMBOL);
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
            (string) ($openTimeMs + 300_000), // 6 close time
            '0', '0', '0', '0', '0', // 7-11 quote vol, trades, taker buy base/quote, ignore
        ]);
    }
}
