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

        $inserted = $repository->save(new ExchangeRate($pair, Rate::fromString('52878.09'), $slot));
        // Same slot, different price — must be ignored.
        $skipped = $repository->save(new ExchangeRate($pair, Rate::fromString('99999.99'), $slot));

        // The bool return is the contract the fetcher reports on: true = inserted.
        $I->assertTrue($inserted);
        $I->assertFalse($skipped);

        $I->assertSame(1, $I->grabNumRecords(ExchangeRateDoctrine::class, ['pair' => 'EUR/BTC']));

        $stored = $I->grabEntityFromRepository(ExchangeRateDoctrine::class, ['pair' => 'EUR/BTC']);
        $I->assertSame('52878.090000000000', $stored->getPrice());
    }

    /**
     * A distinct slot for the same pair is a separate row, and a fresh insert
     * reports true — the upsert only no-ops on the exact (pair, recorded_at) key.
     */
    public function saveStoresDistinctSlotsForTheSamePair(IntegrationTester $I): void
    {
        $repository = $I->grabService(ExchangeRateRepository::class);
        $pair = CurrencyPair::fromString('EUR/ETH');

        $first = $repository->save(new ExchangeRate($pair, Rate::fromString('1300.00'), new \DateTimeImmutable('2026-03-15 12:00:00', new \DateTimeZone('UTC'))));
        $second = $repository->save(new ExchangeRate($pair, Rate::fromString('1357.96'), new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'))));

        $I->assertTrue($first);
        $I->assertTrue($second);
        $I->assertSame(2, $I->grabNumRecords(ExchangeRateDoctrine::class, ['pair' => 'EUR/ETH']));
    }

    /**
     * The fetcher sizes each pair's backfill window from its own latest slot, and
     * the health check judges each pair's freshness individually, so
     * latestRecordedAt is scoped per pair; an unseen pair reports null.
     */
    public function latestRecordedAtIsScopedPerPair(IntegrationTester $I): void
    {
        $repository = $I->grabService(ExchangeRateRepository::class);

        $btc = CurrencyPair::fromString('EUR/BTC');
        $eth = CurrencyPair::fromString('EUR/ETH');

        $btcLatest = new \DateTimeImmutable('2026-03-15 12:10:00', new \DateTimeZone('UTC'));
        $ethLatest = new \DateTimeImmutable('2026-03-15 12:30:00', new \DateTimeZone('UTC'));

        $repository->save(new ExchangeRate($btc, Rate::fromString('52000.00'), new \DateTimeImmutable('2026-03-15 12:05:00', new \DateTimeZone('UTC'))));
        $repository->save(new ExchangeRate($btc, Rate::fromString('52100.00'), $btcLatest));
        $repository->save(new ExchangeRate($eth, Rate::fromString('1300.00'), $ethLatest));

        // Per pair: each pair's own most-recent slot.
        $I->assertEquals($btcLatest, $repository->latestRecordedAt($btc));
        $I->assertEquals($ethLatest, $repository->latestRecordedAt($eth));

        // An unseen pair has no samples yet.
        $I->assertNull($repository->latestRecordedAt(CurrencyPair::fromString('EUR/LTC')));
    }
}
