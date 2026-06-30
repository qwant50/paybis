<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate;

use App\Domain\ExchangeRate\Exception\InvalidDateException;

/**
 * Immutable value object for a single UTC calendar day.
 *
 * Parsing lives here (not in the controller) so that "what counts as a valid
 * day" is defined once, and an invalid input always surfaces as a domain
 * {@see InvalidDateException} rather than an ad-hoc check.
 */
final readonly class Day
{
    private const string FORMAT = 'Y-m-d';

    private function __construct(private \DateTimeImmutable $date)
    {
    }

    /**
     * Parse a strict YYYY-MM-DD string as UTC midnight.
     *
     * @throws InvalidDateException when the input is not a real YYYY-MM-DD date
     */
    public static function fromString(string $date): self
    {
        // The leading "!" resets all fields, so time is fixed at 00:00:00 UTC.
        $parsed = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $date, new \DateTimeZone('UTC'));

        // Re-formatting and comparing rejects out-of-range input that PHP would
        // otherwise overflow (e.g. "2026-13-40").
        if (!$parsed instanceof \DateTimeImmutable || $parsed->format(self::FORMAT) !== $date) {
            throw InvalidDateException::forDate($date);
        }

        return new self($parsed);
    }

    /**
     * UTC midnight at the start of this day.
     */
    public function toDateTime(): \DateTimeImmutable
    {
        return $this->date;
    }
}
