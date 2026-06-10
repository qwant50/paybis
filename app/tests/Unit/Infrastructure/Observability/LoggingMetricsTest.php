<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Observability;

use App\Infrastructure\Observability\LoggingMetrics;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;

final class LoggingMetricsTest extends Unit
{
    public function testIncrementEmitsACounterRecord(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('metric', [
            'metric' => 'binance.fetch',
            'type'   => 'counter',
            'value'  => 1,
            'tags'   => ['pair' => 'EUR/BTC', 'outcome' => 'success'],
        ]);

        new LoggingMetrics($logger)->increment('binance.fetch', tags: ['pair' => 'EUR/BTC', 'outcome' => 'success']);
    }

    public function testTimingEmitsARoundedTimingRecord(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('metric', [
            'metric' => 'binance.fetch.duration_ms',
            'type'   => 'timing',
            'value'  => 12.346,
            'tags'   => ['pair' => 'EUR/BTC'],
        ]);

        new LoggingMetrics($logger)->timing('binance.fetch.duration_ms', 12.3456, ['pair' => 'EUR/BTC']);
    }
}
