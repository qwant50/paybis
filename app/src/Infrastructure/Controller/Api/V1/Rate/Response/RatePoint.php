<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Response;

use OpenApi\Attributes as OA;

/**
 * One point in a rate series: a UTC timestamp and the price at that moment.
 *
 * The price is a string (not a float) rendered at the pair's display precision —
 * see {@see \App\Domain\ExchangeRate\CurrencyPair::displayScale()}.
 */
#[OA\Schema(schema: 'RatePoint')]
final readonly class RatePoint
{
    public function __construct(
        #[OA\Property(type: 'string', format: 'date-time', example: '2026-06-06T15:50:00+00:00')]
        public string $timestamp,
        #[OA\Property(type: 'string', example: '52878.09')]
        public string $price,
    ) {
    }
}
