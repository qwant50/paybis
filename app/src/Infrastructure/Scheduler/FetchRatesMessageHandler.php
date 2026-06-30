<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\ExchangeRate\Service\RateFetcher;
use App\Infrastructure\Logging\CorrelationContext;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Ulid;

#[AsMessageHandler]
final readonly class FetchRatesMessageHandler
{
    public function __construct(
        private RateFetcher $rateFetcher,
        private CorrelationContext $correlation,
    ) {
    }

    public function __invoke(FetchRatesMessage $message): void
    {
        // The worker is long-running and reuses the shared context across messages,
        // so each run gets a fresh id that ties together all of its log lines.
        $this->correlation->set((string) new Ulid());

        $this->rateFetcher->fetchAll();
    }
}
