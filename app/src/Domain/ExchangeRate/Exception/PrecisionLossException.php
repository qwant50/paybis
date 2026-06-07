<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate\Exception;

/**
 * Thrown when a price carries more decimal places than {@see \App\Domain\ExchangeRate\Rate::SCALE}
 * can retain, so storing it would silently lose precision.
 *
 * This is an internal data condition (raised while fetching), never client input,
 * so it is deliberately *not* mapped by the API error listener; the fetch loop
 * isolates and logs it per pair instead.
 */
final class PrecisionLossException extends \RuntimeException
{
    public static function forPrice(string $price, int $scale): self
    {
        return new self(sprintf('Price "%s" exceeds the supported precision of %d decimal places.', $price, $scale));
    }
}
