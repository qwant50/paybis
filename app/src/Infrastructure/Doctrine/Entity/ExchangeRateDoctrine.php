<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Entity;

use App\Infrastructure\Doctrine\Repository\ExchangeRateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persistence model for a single EUR→crypto rate sample captured at a point in time.
 *
 * The Doctrine-mapped counterpart of the domain
 * {@see \App\Domain\ExchangeRate\ExchangeRate}; the two are reconciled by
 * {@see \App\Infrastructure\Doctrine\Mapper\ExchangeRateMapper}. `price` is stored
 * as a fixed-scale DECIMAL (mapped to a PHP string) so no precision is lost; use
 * {@see \App\Domain\ExchangeRate\Rate} to manipulate it.
 */
#[ORM\Entity(repositoryClass: ExchangeRateRepository::class)]
#[ORM\Table(name: 'exchange_rate')]
#[ORM\Index(name: 'idx_exchange_rate_pair_recorded_at', columns: ['pair', 'recorded_at'])]
#[ORM\HasLifecycleCallbacks]
class ExchangeRateDoctrine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine on persist)

    #[ORM\Column(length: 16)]
    private string $pair;

    #[ORM\Column(type: Types::DECIMAL, precision: 30, scale: 12)]
    private string $price;

    #[ORM\Column(name: 'recorded_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $pair, string $price, \DateTimeImmutable $recordedAt)
    {
        $this->pair = $pair;
        $this->price = $price;
        $this->recordedAt = $recordedAt;
    }

    #[ORM\PrePersist]
    public function initialiseCreatedAt(): void
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPair(): string
    {
        return $this->pair;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
