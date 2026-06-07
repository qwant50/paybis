<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use App\Infrastructure\Doctrine\Mapper\ExchangeRateMapper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function save(ExchangeRate $exchangeRate): void
    {
        $this->getEntityManager()->persist($this->mapper->domainToDoctrine($exchangeRate));
        $this->getEntityManager()->flush();
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
}
