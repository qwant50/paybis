<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate;

use App\Domain\ExchangeRate\Exception\InvalidPriceException;
use App\Domain\ExchangeRate\Exception\PrecisionLossException;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;

/**
 * Immutable value object for an EUR-denominated exchange rate.
 *
 * Wraps brick/money so the rate is handled with arbitrary precision (12 decimal
 * places, a fixed ceiling that covers Binance crypto prices down to micro-cap
 * territory) instead of lossy floats. The value is the EUR price of one unit of
 * the crypto asset (e.g. EUR per BTC).
 *
 * This is the storage/arithmetic scale and is intentionally separate from the
 * per-pair *display* precision (see {@see CurrencyPair::displayScale()}); a value
 * is stored in full and only trimmed to the pair's tick size when rendered.
 */
final readonly class Rate
{
    /** Decimal places retained for storage and arithmetic. */
    public const int SCALE = 12;

    private function __construct(private Money $amount)
    {
    }

    /**
     * Build a rate from a Binance ticker price string (e.g. "95123.45000000").
     *
     * Parsing uses {@see RoundingMode::Unnecessary} so a price with more decimals
     * than {@see self::SCALE} is never silently truncated — it raises
     * {@see PrecisionLossException} instead, signalling that the storage scale
     * needs widening for that asset.
     *
     * A non-positive price raises {@see InvalidPriceException}: zero or negative
     * is definitionally invalid for an exchange rate, and the idempotent store
     * never overwrites, so a corrupt value let through here would become
     * permanent history.
     *
     * @throws PrecisionLossException|MathException when the price has more than self::SCALE decimals
     * @throws InvalidPriceException when the price is zero or negative
     */
    public static function fromString(string $price): self
    {
        try {
            $amount = Money::of($price, 'EUR', new CustomContext(self::SCALE), RoundingMode::Unnecessary);
        } catch (RoundingNecessaryException $e) {
            throw PrecisionLossException::forPrice($price, self::SCALE);
        }

        if (!$amount->isPositive()) {
            throw InvalidPriceException::forNonPositive($price);
        }

        return new self($amount);
    }

    /**
     * The rate as a fixed-scale decimal string, suitable for DECIMAL storage.
     */
    public function asString(): string
    {
        return (string) $this->amount->getAmount();
    }

    /**
     * The rate rendered at a given display precision (e.g. a pair's tick-size
     * scale), rounding half-up. Used for presentation, not storage.
     *
     * @param int<0, max> $scale
     */
    public function format(int $scale): string
    {
        return (string) $this->amount->getAmount()->toScale($scale, RoundingMode::HalfUp);
    }

    public function toFloat(): float
    {
        return $this->amount->getAmount()->toFloat();
    }

    public function money(): Money
    {
        return $this->amount;
    }
}
