<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate;

/**
 * Immutable domain model for a single EUR→crypto rate captured at a point in time.
 *
 * Composes the domain value objects ({@see CurrencyPair}, {@see Rate}) with the
 * UTC capture time, so the Application layer traffics in pure domain types and
 * never touches the Doctrine entity ({@see \App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine}).
 * It carries no persistence identity — the database `id` is an Infrastructure
 * concern that no consumer needs.
 */
final readonly class ExchangeRate
{
    public function __construct(
        public CurrencyPair $pair,
        public Rate $rate,
        public \DateTimeImmutable $recordedAt,
    ) {
    }
}
