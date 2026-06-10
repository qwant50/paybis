<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use App\Infrastructure\Doctrine\Mapper\ExchangeRateMapper;
use App\Infrastructure\Doctrine\Type\DateTimeImmutableMicrosecondType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Doctrine adapter for the {@see RateRepository} port. Persistence concerns only;
 * the entity ↔ domain {@see ExchangeRate} translation is delegated to
 * {@see ExchangeRateMapper}.
 *
 * @extends ServiceEntityRepository<ExchangeRateDoctrine>
 */
class ExchangeRateRepository extends ServiceEntityRepository implements RateRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ExchangeRateMapper $mapper)
    {
        parent::__construct($registry, ExchangeRateDoctrine::class);
    }

    public function save(ExchangeRate $exchangeRate): bool
    {
        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO exchange_rate (pair, price, recorded_at, created_at)
             VALUES (:pair, :price, :recorded_at, :created_at)
             ON DUPLICATE KEY UPDATE id = id',
            [
                'pair'        => $exchangeRate->pair->value(),
                'price'       => $exchangeRate->rate->asString(),
                'recorded_at' => $exchangeRate->recordedAt,
                'created_at'  => new \DateTimeImmutable(),
            ],
            [
                'recorded_at' => Types::DATETIME_IMMUTABLE,
                'created_at'  => DateTimeImmutableMicrosecondType::NAME,
            ],
        );

        return (int) $affected > 0;
    }

    /**
     * @return list<ExchangeRate>
     */
    public function findBetween(CurrencyPair $pair, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /** @var list<ExchangeRateDoctrine> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('r.pair = :pair')
            ->andWhere('r.recordedAt >= :from')
            ->andWhere('r.recordedAt < :to')
            ->setParameter('pair', $pair->value())
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.recordedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map($this->mapper->doctrineToDomain(...), $rows);
    }

    public function latestRecordedAt(CurrencyPair $pair): ?\DateTimeImmutable
    {
        /** @var ExchangeRateDoctrine|null $row */
        $row = $this->createQueryBuilder('r')
            ->andWhere('r.pair = :pair')
            ->setParameter('pair', $pair->value())
            ->orderBy('r.recordedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $row?->getRecordedAt();
    }
}
