<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Binance;

use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use App\Infrastructure\Binance\RetryingPriceHistoryProvider;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class RetryingPriceHistoryProviderTest extends Unit
{
    private const string SYMBOL = 'BTCEUR';

    public function testItReturnsTheInnerResultWithoutRetryingOnSuccess(): void
    {
        $points = [new PricePoint('52000.00', new \DateTimeImmutable('@1700000000'))];

        $inner = $this->createMock(PriceHistoryProvider::class);
        $inner->expects($this->once())->method('recentPricePoints')->with(self::SYMBOL)->willReturn($points);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $provider = new RetryingPriceHistoryProvider($inner, $logger, maxAttempts: 3, baseDelayMs: 0);

        $this->assertSame($points, $provider->recentPricePoints(self::SYMBOL));
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

        $this->assertSame($points, $provider->recentPricePoints(self::SYMBOL));
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

        $provider->recentPricePoints(self::SYMBOL);
    }
}
