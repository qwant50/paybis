<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Mapper;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;

/**
 * Translates between the domain {@see ExchangeRate} model and the stored
 * {@see ExchangeRateDoctrine} entity — the single boundary where the domain and
 * persistence representations are reconciled.
 *
 * Both sides expose proper constructors/getters, so the mapping needs no
 * reflection: the domain model becomes an entity via the entity constructor, and
 * an entity becomes the domain model by re-parsing its stored strings into value
 * objects.
 */
final class ExchangeRateMapper
{
    public function domainToDoctrine(ExchangeRate $exchangeRate): ExchangeRateDoctrine
    {
        return new ExchangeRateDoctrine(
            $exchangeRate->pair->value(),
            $exchangeRate->rate->asString(),
            $exchangeRate->recordedAt,
        );
    }

    public function doctrineToDomain(ExchangeRateDoctrine $entity): ExchangeRate
    {
        return new ExchangeRate(
            CurrencyPair::fromString($entity->getPair()),
            Rate::fromString($entity->getPrice()),
            $entity->getRecordedAt(),
        );
    }
}
