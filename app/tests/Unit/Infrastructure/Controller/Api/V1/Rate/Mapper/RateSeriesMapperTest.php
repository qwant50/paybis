<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api\V1\Rate\Mapper;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Infrastructure\Controller\Api\V1\Rate\Mapper\RateSeriesMapper;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RatePoint;
use Codeception\Test\Unit;

final class RateSeriesMapperTest extends Unit
{
    private RateSeriesMapper $mapper;

    protected function _before(): void
    {
        $this->mapper = new RateSeriesMapper();
    }

    public function testItMapsPairValueAndPoints(): void
    {
        $pair = CurrencyPair::fromString('EUR/BTC');
        $recordedAt = new \DateTimeImmutable('2026-06-06 15:52:00', new \DateTimeZone('UTC'));

        $response = $this->mapper->toResponse($pair, [
            new ExchangeRate($pair, Rate::fromString('52878.09000000'), $recordedAt),
        ]);

        $this->assertSame('EUR/BTC', $response->pair);
        $this->assertCount(1, $response->points);

        $point = $response->points[0];
        $this->assertInstanceOf(RatePoint::class, $point);
        $this->assertSame('2026-06-06T15:52:00+00:00', $point->timestamp);
    }

    public function testItRendersPriceAtPairDisplayScale(): void
    {
        $pair = CurrencyPair::fromString('EUR/BTC');

        $response = $this->mapper->toResponse($pair, [
            new ExchangeRate($pair, Rate::fromString('52878.09000000'), new \DateTimeImmutable()),
        ]);

        // EUR/BTC tick size is 0.01 -> two decimals, not the 12-decimal stored value.
        $this->assertSame('52878.09', $response->points[0]->price);
    }

    public function testItReturnsEmptyPointsForNoSamples(): void
    {
        $response = $this->mapper->toResponse(CurrencyPair::fromString('EUR/BTC'), []);

        $this->assertSame('EUR/BTC', $response->pair);
        $this->assertSame([], $response->points);
    }
}
