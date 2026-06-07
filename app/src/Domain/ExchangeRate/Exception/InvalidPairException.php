<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate\Exception;

/**
 * Thrown when a requested currency pair is not supported by the application.
 */
final class InvalidPairException extends \InvalidArgumentException
{
    public static function forPair(string $pair, string $supported): self
    {
        return new self(sprintf('Unsupported currency pair "%s". Supported pairs: %s.', $pair, $supported));
    }
}
