<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate\Exception;

/**
 * Thrown when a price is not a positive amount — an exchange rate of zero or
 * less is definitionally invalid, and letting one through would write a corrupt
 * value into immutable history (the idempotent store never overwrites, so it
 * could not be re-fetched into correctness later).
 *
 * Like {@see PrecisionLossException}, this is an internal data condition (raised
 * while fetching), never client input, so it is deliberately *not* mapped by the
 * API error listener; the fetch loop isolates and logs it per point instead.
 */
final class InvalidPriceException extends \RuntimeException
{
    public static function forNonPositive(string $price): self
    {
        return new self(sprintf('Price "%s" is not positive; an exchange rate must be greater than zero.', $price));
    }
}
