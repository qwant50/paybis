<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use App\Application\Service\RateFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class FetchRatesMessageHandler
{
    public function __construct(private RateFetcher $rateFetcher)
    {
    }

    public function __invoke(FetchRatesMessage $message): void
    {
        $this->rateFetcher->fetchAll();
    }
}
