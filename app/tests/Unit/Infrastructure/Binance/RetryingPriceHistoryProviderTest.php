<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Binance;

use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Infrastructure\Binance\RateLimitException;
use App\Infrastructure\Binance\RetryingPriceHistoryProvider;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RetryingPriceHistoryProviderTest extends Unit
{
    private const string SYMBOL = 'BTCEUR';
    private const int LIMIT = 12;

    public function testItReturnsTheInnerResultWithoutRetryingOnSuccess(): void
    {
        $points = [new PricePoint('52000.00', new \DateTimeImmutable('@1700000000'))];

        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->once())->method('recentPricePoints')->with(self::SYMBOL, self::LIMIT)->willReturn($points);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $provider = new RetryingPriceHistoryProvider($inner, $logger, maxAttempts: 3, baseDelayMs: 0);

        $this->assertSame($points, $provider->recentPricePoints(self::SYMBOL, self::LIMIT));
    }

    public function testItRetriesTransientFailuresThenSucceeds(): void
    {
        $points = [new PricePoint('52000.00', new \DateTimeImmutable('@1700000000'))];

        $calls = 0;
        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->exactly(3))
            ->method('recentPricePoints')
            ->willReturnCallback(static function () use (&$calls, $points): array {
                ++$calls;
                if ($calls < 3) {
                    throw new \RuntimeException('transient blip');
                }

                return $points;
            });

        // One warning per retry: attempts 1 and 2 failed before the 3rd succeeded.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');

        $provider = new RetryingPriceHistoryProvider($inner, $logger, maxAttempts: 3, baseDelayMs: 0);

        $this->assertSame($points, $provider->recentPricePoints(self::SYMBOL, self::LIMIT));
    }

    public function testItRethrowsTheLastExceptionAfterExhaustingAttempts(): void
    {
        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->exactly(3))
            ->method('recentPricePoints')
            ->willThrowException(new \RuntimeException('Binance is down'));

        $logger = $this->createMock(LoggerInterface::class);

        $provider = new RetryingPriceHistoryProvider($inner, $logger, maxAttempts: 3, baseDelayMs: 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Binance is down');

        $provider->recentPricePoints(self::SYMBOL, self::LIMIT);
    }

    public function testItDoesNotRetryWhenRateLimited(): void
    {
        // Fast in-run retries during a rate-limit event add load exactly when the
        // exchange asks for less (and escalate toward the 418 IP ban); the next
        // scheduled run is the retry, so the failure must propagate immediately.
        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->once())
            ->method('recentPricePoints')
            ->willThrowException(new RateLimitException('429 too many requests'));

        $provider = new RetryingPriceHistoryProvider($inner, $this->createMock(LoggerInterface::class), maxAttempts: 3, baseDelayMs: 0);

        $this->expectException(RateLimitException::class);

        $provider->recentPricePoints(self::SYMBOL, self::LIMIT);
    }

    public function testItAlsoRetriesRangedFetches(): void
    {
        $from = new \DateTimeImmutable('2026-03-15 00:00:00', new \DateTimeZone('UTC'));
        $to = new \DateTimeImmutable('2026-03-16 00:00:00', new \DateTimeZone('UTC'));
        $points = [new PricePoint('52000.00', $from)];

        $calls = 0;
        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->exactly(2))
            ->method('pricePointsBetween')
            ->with(self::SYMBOL, $from, $to)
            ->willReturnCallback(static function () use (&$calls, $points): array {
                ++$calls;
                if ($calls < 2) {
                    throw new \RuntimeException('transient blip');
                }

                return $points;
            });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $provider = new RetryingPriceHistoryProvider($inner, $logger, maxAttempts: 3, baseDelayMs: 0);

        $this->assertSame($points, $provider->pricePointsBetween(self::SYMBOL, $from, $to));
    }
}
