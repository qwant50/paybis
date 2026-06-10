<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Doctrine\Mapper;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use App\Infrastructure\Doctrine\Mapper\ExchangeRateMapper;
use Codeception\Test\Unit;

final class ExchangeRateMapperTest extends Unit
{
    private ExchangeRateMapper $mapper;

    protected function _before(): void
    {
        $this->mapper = new ExchangeRateMapper();
    }

    public function testItMapsDomainModelToEntity(): void
    {
        $recordedAt = new \DateTimeImmutable('2026-06-06 15:52:00', new \DateTimeZone('UTC'));
        $exchangeRate = new ExchangeRate(CurrencyPair::fromString('EUR/BTC'), Rate::fromString('52878.09'), $recordedAt);

        $entity = $this->mapper->domainToDoctrine($exchangeRate);

        $this->assertSame('EUR/BTC', $entity->getPair());
        // Stored at the fixed 12-decimal scale, ready for DECIMAL storage.
        $this->assertSame('52878.090000000000', $entity->getPrice());
        $this->assertSame($recordedAt, $entity->getRecordedAt());
    }

    public function testItMapsEntityToDomainModel(): void
    {
        $recordedAt = new \DateTimeImmutable('2026-06-06 15:52:00', new \DateTimeZone('UTC'));
        $entity = new ExchangeRateDoctrine('EUR/ETH', '1357.960000000000', $recordedAt);

        $exchangeRate = $this->mapper->doctrineToDomain($entity);

        $this->assertSame('EUR/ETH', $exchangeRate->pair->value());
        $this->assertSame('1357.960000000000', $exchangeRate->rate->asString());
        $this->assertSame($recordedAt, $exchangeRate->recordedAt);
    }

    public function testRoundTripPreservesFullPrecisionAndPair(): void
    {
        $recordedAt = new \DateTimeImmutable('2026-06-06 15:52:00', new \DateTimeZone('UTC'));
        $exchangeRate = new ExchangeRate(CurrencyPair::fromString('EUR/LTC'), Rate::fromString('36.123456789012'), $recordedAt);

        $roundTripped = $this->mapper->doctrineToDomain($this->mapper->domainToDoctrine($exchangeRate));

        $this->assertSame('EUR/LTC', $roundTripped->pair->value());
        $this->assertSame('36.123456789012', $roundTripped->rate->asString());
        $this->assertEquals($recordedAt, $roundTripped->recordedAt);
    }
}
