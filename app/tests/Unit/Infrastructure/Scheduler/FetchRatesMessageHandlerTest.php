<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Scheduler;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Application\ExchangeRate\Service\RateFetcher;
use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Logging\CorrelationContext;
use App\Infrastructure\Scheduler\FetchRatesMessage;
use App\Infrastructure\Scheduler\FetchRatesMessageHandler;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class FetchRatesMessageHandlerTest extends Unit
{
    public function testItSetsAFreshCorrelationIdForTheRun(): void
    {
        $context = new CorrelationContext();
        $handler = new FetchRatesMessageHandler($this->rateFetcher(), $context);

        $handler(new FetchRatesMessage());

        // The run now carries a ULID, so all of its log lines can be correlated.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $context->current());
    }

    /**
     * RateFetcher is final (unmockable); a real instance over mocked collaborators
     * that yield no points makes fetchAll() an effective no-op for this test.
     */
    private function rateFetcher(): RateFetcher
    {
        $provider = $this->createMock(PriceHistoryProvider::class);
        $provider->method('recentPricePoints')->willReturn([]);

        $repository = $this->createMock(RateRepository::class);
        $logger = $this->createMock(LoggerInterface::class);

        return new RateFetcher(
            $provider,
            $repository,
            new PricePointPersister($repository, $logger, $this->createMock(Metrics::class)),
            $logger,
            $this->createMock(Metrics::class),
            new MockClock(),
        );
    }
}
