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

    public function testItExcludesTheStillFormingCandleAndReturnsTheLatestClosedOne(): void
    {
        $now = self::nowMs();
        $gridOpen = (new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC')))->getTimestamp() * 1000;

        // Ascending by open time; the last candle is still forming (close time in
        // the future), the middle one is the most recent *closed* candle.
        $service = $this->serviceReturning([
            self::row($gridOpen - 300_000, '52000.00', $now - 600_000),
            self::row($gridOpen, '52878.09', $now - 300_000),
            self::row($gridOpen + 300_000, '52999.99', $now + 300_000),
        ]);

        $candle = $service->latestClosedCandle(self::SYMBOL);

        $this->assertSame('52878.09', $candle->closePrice);
        $this->assertEquals(
            new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC')),
            $candle->openTime,
        );
        $this->assertSame('00', $candle->openTime->format('s'));
    }

    public function testItFallsBackToAnOlderClosedCandleAtAnIntervalBoundary(): void
    {
        $now = self::nowMs();

        // Simulated boundary + clock skew: the freshest just-closed candle's close
        // time reads as not-yet-passed, and the last candle is forming. The older
        // candle is unambiguously closed, so a valid candle is still returned.
        $service = $this->serviceReturning([
            self::row(1_700_000_000_000, '49000.00', $now - 300_000),
            self::row(1_700_000_300_000, '49500.00', $now + 1_000),
            self::row(1_700_000_600_000, '49750.00', $now + 300_000),
        ]);

        $candle = $service->latestClosedCandle(self::SYMBOL);

        $this->assertSame('49000.00', $candle->closePrice);
    }

    public function testItThrowsWhenNoClosedCandleIsAvailable(): void
    {
        $now = self::nowMs();
        $service = $this->serviceReturning([
            self::row(1_700_000_000_000, '49000.00', $now + 300_000),
            self::row(1_700_000_300_000, '49500.00', $now + 600_000),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no closed candle/i');

        $service->latestClosedCandle(self::SYMBOL);
    }

    public function testItThrowsWhenTheClosedCandleHasAnEmptyClosePrice(): void
    {
        $now = self::nowMs();
        $service = $this->serviceReturning([
            self::row(1_700_000_000_000, '', $now - 300_000),
            self::row(1_700_000_300_000, '49500.00', $now + 300_000),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty close price/i');

        $service->latestClosedCandle(self::SYMBOL);
    }

    public function testItWrapsApiExceptionsWithTheSymbol(): void
    {
        $api = $this->createMock(SpotRestApi::class);
        $api->method('klines')->willThrowException(new ApiException('boom'));
        $service = new BinanceService($api);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/BTCEUR/');

        $service->latestClosedCandle(self::SYMBOL);
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
     * A raw Binance kline row: [0]=openTime ms, [4]=close, [6]=closeTime ms.
     */
    private static function row(int $openTimeMs, string $close, int $closeTimeMs): KlinesItem
    {
        return new KlinesItem([
            (string) $openTimeMs, // 0 open time
            '0', '0', '0',        // 1 open, 2 high, 3 low
            $close,               // 4 close
            '0',                  // 5 volume
            (string) $closeTimeMs, // 6 close time
            '0', '0', '0', '0', '0', // 7-11 quote vol, trades, taker buy base/quote, ignore
        ]);
    }

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
