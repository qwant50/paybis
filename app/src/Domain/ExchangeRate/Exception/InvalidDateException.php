<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate\Exception;

/**
 * Thrown when a requested day cannot be parsed as a valid UTC calendar date.
 */
final class InvalidDateException extends \InvalidArgumentException
{
    public static function forDate(string $date): self
    {
        return new self(sprintf('Invalid date "%s". Expected format YYYY-MM-DD.', $date));
    }
}
