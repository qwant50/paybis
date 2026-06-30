<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ExchangeRate;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\Exception\InvalidPairException;
use Codeception\Test\Unit;

final class CurrencyPairTest extends Unit
{
    public function testItMapsSupportedPairsToBinanceSymbols(): void
    {
        $this->assertSame('BTCEUR', CurrencyPair::fromString('EUR/BTC')->binanceSymbol());
        $this->assertSame('ETHEUR', CurrencyPair::fromString('EUR/ETH')->binanceSymbol());
        $this->assertSame('LTCEUR', CurrencyPair::fromString('EUR/LTC')->binanceSymbol());
    }

    public function testItExposesTheTickSizeAndDerivesDisplayScale(): void
    {
        $pair = CurrencyPair::fromString('EUR/BTC');

        $this->assertSame('0.01', $pair->tickSize());
        $this->assertSame(2, $pair->displayScale());
    }

    public function testItNormalisesCaseAndWhitespace(): void
    {
        $pair = CurrencyPair::fromString('  eur/btc  ');

        $this->assertSame('EUR/BTC', $pair->value());
        $this->assertSame('EUR/BTC', (string) $pair);
    }

    public function testItRejectsUnsupportedPairs(): void
    {
        $this->expectException(InvalidPairException::class);

        CurrencyPair::fromString('EUR/DOGE');
    }

    public function testItRejectsEmptyInput(): void
    {
        $this->expectException(InvalidPairException::class);

        CurrencyPair::fromString('');
    }

    public function testAllReturnsEverySupportedPair(): void
    {
        $values = array_map(static fn (CurrencyPair $p): string => $p->value(), CurrencyPair::all());

        $this->assertSame(['EUR/BTC', 'EUR/ETH', 'EUR/LTC'], $values);
    }

    public function testSupportedConstStaysInSyncWithTheMap(): void
    {
        // SUPPORTED exists only because PHP attributes need a constant array; this
        // guards it against drifting from the runtime source (MAP via supportedPairs()).
        $this->assertSame(CurrencyPair::supportedPairs(), CurrencyPair::SUPPORTED);
    }

    public function testEquals(): void
    {
        $this->assertTrue(CurrencyPair::fromString('EUR/BTC')->equals(CurrencyPair::fromString('eur/btc')));
        $this->assertFalse(CurrencyPair::fromString('EUR/BTC')->equals(CurrencyPair::fromString('EUR/ETH')));
    }
}
