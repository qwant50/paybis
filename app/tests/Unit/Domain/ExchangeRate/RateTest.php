<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ExchangeRate;

use App\Domain\ExchangeRate\Exception\InvalidPriceException;
use App\Domain\ExchangeRate\Exception\PrecisionLossException;
use App\Domain\ExchangeRate\Rate;
use Codeception\Test\Unit;

final class RateTest extends Unit
{
    public function testItStoresValueAtFixedScale(): void
    {
        $this->assertSame('52878.090000000000', Rate::fromString('52878.09')->asString());
        $this->assertSame('36.870000000000', Rate::fromString('36.87')->asString());
    }

    public function testItPreservesUpToTwelveDecimals(): void
    {
        $this->assertSame('0.000000012345', Rate::fromString('0.000000012345')->asString());
    }

    public function testItThrowsWhenPriceExceedsTwelveDecimals(): void
    {
        $this->expectException(PrecisionLossException::class);

        Rate::fromString('1.2345678901239');
    }

    public function testItThrowsOnAZeroPrice(): void
    {
        // An exchange rate of zero is definitionally invalid — letting one through
        // would write a corrupt value into immutable history (the idempotent store
        // never overwrites, so it could not be re-fetched into correctness).
        $this->expectException(InvalidPriceException::class);

        Rate::fromString('0.00000000');
    }

    public function testItThrowsOnANegativePrice(): void
    {
        $this->expectException(InvalidPriceException::class);

        Rate::fromString('-1.50');
    }

    public function testFormatTrimsToDisplayScale(): void
    {
        $this->assertSame('95123.45', Rate::fromString('95123.45')->format(2));
        $this->assertSame('0.15432', Rate::fromString('0.154321')->format(5));
    }

    public function testFormatRoundsHalfUp(): void
    {
        $this->assertSame('1.24', Rate::fromString('1.235')->format(2));
    }

    public function testToFloat(): void
    {
        $this->assertSame(1357.96, Rate::fromString('1357.96')->toFloat());
    }
}
