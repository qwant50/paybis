<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\ExchangeRate\Service\RateFeedIntegrityChecker;
use App\Infrastructure\Logging\CorrelationContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final readonly class CheckFeedIntegrityMessageHandler
{
    public function __construct(
        private RateFeedIntegrityChecker $checker,
        private CorrelationContext $correlation,
    ) {
    }

    public function __invoke(CheckFeedIntegrityMessage $message): void
    {
        // The worker is long-running and reuses the shared context across messages,
        // so each run gets a fresh id that ties together all of its log lines.
        $this->correlation->set((string) new Ulid());

        $this->checker->check();
    }
}
