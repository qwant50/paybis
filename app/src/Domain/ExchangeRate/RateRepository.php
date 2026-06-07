<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate;

/**
 * Persistence port for rate samples, expressed purely in domain types.
 *
 * The Doctrine repository is the adapter that implements this and is the single
 * place that maps between the {@see ExchangeRate} model and the stored entity.
 * Keeping the port in the Domain lets the Application layer depend only inward.
 */
interface RateRepository
{
    public function save(ExchangeRate $exchangeRate): void;

    /**
     * Exchange rates for a pair within the half-open interval [$from, $to),
     * ordered chronologically.
     *
     * @return list<ExchangeRate>
     */
    public function findBetween(CurrencyPair $pair, \DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
