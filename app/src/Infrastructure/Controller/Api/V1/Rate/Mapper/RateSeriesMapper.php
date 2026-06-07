<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Mapper;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RatePoint;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RateSeriesResponse;

/**
 * Maps {@see ExchangeRate} domain models to the {@see RateSeriesResponse} DTO.
 *
 * A pure, framework-free transformation (no HTTP): timestamps are rendered as
 * ISO-8601 UTC and prices are trimmed to the pair's display precision. Kept apart
 * from {@see \App\Infrastructure\Controller\Api\ApiResponder} so the mapping is
 * unit-testable without the HTTP stack.
 */
final class RateSeriesMapper
{
    /**
     * @param list<ExchangeRate> $rates chronological samples
     */
    public function toResponse(CurrencyPair $pair, array $rates): RateSeriesResponse
    {
        $scale = $pair->displayScale();

        $points = array_map(
            static fn (ExchangeRate $rate): RatePoint => new RatePoint(
                $rate->recordedAt->format(\DateTimeInterface::ATOM),
                $rate->rate->format($scale),
            ),
            $rates,
        );

        return new RateSeriesResponse($pair->value(), $points);
    }
}
