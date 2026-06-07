<?php

declare(strict_types=1);

namespace App\Domain\ExchangeRate;

use App\Domain\ExchangeRate\Exception\InvalidPairException;

/**
 * Immutable value object for a supported EUR→crypto pair.
 *
 * The public API speaks in "EUR/BTC" form, while Binance trades the inverse
 * symbol "BTCEUR" (the EUR price of one unit of crypto). This object is the
 * single place that maps between the two, so the two representations can never
 * drift apart.
 *
 * Each pair also carries its Binance price `tickSize` (verbatim from the
 * exchange's `exchangeInfo`), from which the per-pair *display* precision is
 * derived — the number of decimals a price is rendered with. This is purely a
 * presentation concern and is independent of the loss-free storage scale (see
 * {@see Rate::SCALE}).
 */
final readonly class CurrencyPair
{
    /**
     * Public pair => {Binance ticker symbol, Binance price tick size}.
     *
     * @var array<string, array{symbol: string, tickSize: string}>
     */
    private const array MAP = [
        'EUR/BTC' => ['symbol' => 'BTCEUR', 'tickSize' => '0.01'],
        'EUR/ETH' => ['symbol' => 'ETHEUR', 'tickSize' => '0.01'],
        'EUR/LTC' => ['symbol' => 'LTCEUR', 'tickSize' => '0.01'],
    ];

    /**
     * The supported public pairs as a flat list — the single literal the API
     * docs reference (PHP attributes accept `CurrencyPair::SUPPORTED`), kept in
     * sync with {@see self::MAP} by {@see self::supportedPairs()}.
     *
     * @var list<string>
     */
    public const array SUPPORTED = ['EUR/BTC', 'EUR/ETH', 'EUR/LTC'];

    private function __construct(
        private string $value,
        private string $binanceSymbol,
        private string $tickSize,
    ) {
    }

    public static function fromString(string $pair): self
    {
        $normalized = strtoupper(trim($pair));

        if (!isset(self::MAP[$normalized])) {
            throw InvalidPairException::forPair($pair, implode(', ', self::supportedPairs()));
        }

        return new self($normalized, self::MAP[$normalized]['symbol'], self::MAP[$normalized]['tickSize']);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(self::fromString(...), self::supportedPairs());
    }

    /**
     * @return list<string>
     */
    public static function supportedPairs(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The public pair representation, e.g. "EUR/BTC".
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * The Binance ticker symbol, e.g. "BTCEUR".
     */
    public function binanceSymbol(): string
    {
        return $this->binanceSymbol;
    }

    /**
     * The Binance price tick size, e.g. "0.01".
     */
    public function tickSize(): string
    {
        return $this->tickSize;
    }

    /**
     * Number of decimals to render a price with, derived from the tick size
     * (e.g. "0.01" → 2, "0.00001" → 5, "1" → 0). Display precision only.
     *
     * @return int<0, max>
     */
    public function displayScale(): int
    {
        $tick = rtrim($this->tickSize, '0');
        $dot = strpos($tick, '.');

        return $dot === false ? 0 : max(0, strlen($tick) - $dot - 1);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
