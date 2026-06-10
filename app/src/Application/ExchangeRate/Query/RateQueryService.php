<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Query;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;

/**
 * Read-side queries backing the rate API endpoints. All time windows are
 * computed in UTC, matching how samples are stored.
 */
final readonly class RateQueryService
{
    public function __construct(private RateRepository $repository)
    {
    }

    /**
     * Rate samples for the 24 hours ending now.
     *
     * @return list<ExchangeRate>
     */
    public function lastDay(CurrencyPair $pair, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->repository->findBetween($pair, $now->sub(new \DateInterval('PT24H')), $now);
    }

    /**
     * Rate samples for a single UTC calendar day [00:00, next 00:00).
     *
     * @return list<ExchangeRate>
     */
    public function forDay(CurrencyPair $pair, \DateTimeImmutable $day): array
    {
        $start = $day->setTime(0, 0, 0);
        $end = $start->add(new \DateInterval('P1D'));

        return $this->repository->findBetween($pair, $start, $end);
    }
}
