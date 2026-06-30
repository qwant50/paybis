<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Scheduler;

use App\Application\ExchangeRate\Service\Metrics;
use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePointPersister;
use App\Application\ExchangeRate\Service\RateBackfiller;
use App\Application\ExchangeRate\Service\RateFeedIntegrityChecker;
use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Logging\CorrelationContext;
use App\Infrastructure\Scheduler\CheckFeedIntegrityMessage;
use App\Infrastructure\Scheduler\CheckFeedIntegrityMessageHandler;
use Codeception\Test\Unit;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class CheckFeedIntegrityMessageHandlerTest extends Unit
{
    public function testItSetsAFreshCorrelationIdForTheRun(): void
    {
        $context = new CorrelationContext();
        $handler = new CheckFeedIntegrityMessageHandler($this->checker(), $context);

        $handler(new CheckFeedIntegrityMessage());

        // The run now carries a ULID, so all of its log lines can be correlated.
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $context->current());
    }

    /**
     * RateFeedIntegrityChecker is final (unmockable); a real instance over mocked
     * collaborators that have nothing stored makes check() an effective no-op here.
     */
    private function checker(): RateFeedIntegrityChecker
    {
        $repository = $this->createMock(RateRepository::class);
        $repository->method('findBetween')->willReturn([]);
        $logger = $this->createMock(LoggerInterface::class);

        return new RateFeedIntegrityChecker(
            $repository,
            new RateBackfiller(
                $this->createMock(PriceHistoryProvider::class),
                new PricePointPersister($repository, $logger, $this->createMock(Metrics::class)),
                $logger,
                $this->createMock(Metrics::class),
            ),
            $logger,
            $this->createMock(Metrics::class),
            new MockClock(),
        );
    }
}
