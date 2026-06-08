<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Doctrine;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use App\Infrastructure\Doctrine\Repository\ExchangeRateRepository;
use Tests\Support\IntegrationTester;

final class ExchangeRateRepositoryCest
{
    /**
     * A closed candle is immutable history: storing the same (pair, slot) again —
     * e.g. a manual fetch, a worker restart, or a scheduler catch-up — must be a
     * no-op that keeps the first price, never an overwrite or a duplicate row.
     */
    public function saveIsIdempotentPerSlotAndKeepsTheFirstPrice(IntegrationTester $I): void
    {
        $repository = $I->grabService(ExchangeRateRepository::class);

        $pair = CurrencyPair::fromString('EUR/BTC');
        $slot = new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'));

        $repository->save(new ExchangeRate($pair, Rate::fromString('52878.09'), $slot));
        // Same slot, different price — must be ignored.
        $repository->save(new ExchangeRate($pair, Rate::fromString('99999.99'), $slot));

        $I->assertSame(1, $I->grabNumRecords(ExchangeRateDoctrine::class, ['pair' => 'EUR/BTC']));

        $stored = $I->grabEntityFromRepository(ExchangeRateDoctrine::class, ['pair' => 'EUR/BTC']);
        $I->assertSame('52878.090000000000', $stored->getPrice());
    }
}
