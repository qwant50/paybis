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
    /**
     * Persist a rate sample. Idempotent per (pair, recorded-at slot): a sample
     * already stored for that slot is left untouched — historical prices are
     * never overwritten.
     *
     * @return bool true if a new sample was inserted, false if the slot already existed
     */
    public function save(ExchangeRate $exchangeRate): bool;

    /**
     * Exchange rates for a pair within the half-open interval [$from, $to),
     * ordered chronologically.
     *
     * @return list<ExchangeRate>
     */
    public function findBetween(CurrencyPair $pair, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /**
     * The recorded-at time of the pair's most recent sample, or null if none is
     * stored yet. The fetcher uses it to size its backfill window to the pair's
     * own trailing gap; the health check uses it to judge each pair's freshness
     * individually (a cross-pair latest would mask a single dead pair).
     */
    public function latestRecordedAt(CurrencyPair $pair): ?\DateTimeImmutable;
}
