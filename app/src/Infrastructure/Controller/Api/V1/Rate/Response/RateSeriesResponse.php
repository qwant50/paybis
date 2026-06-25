<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Response;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * The JSON payload returned by the Rate series endpoints.
 *
 * Public properties define the wire contract: encoded as
 * {@code {"pair": "...", "points": [{"timestamp": "...", "price": "..."}]}}.
 */
#[OA\Schema(schema: 'RateSeriesResponse')]
final readonly class RateSeriesResponse
{
    /**
     * @param list<RatePoint> $points chronological samples
     */
    public function __construct(
        #[OA\Property(type: 'string', example: 'EUR/BTC')]
        public string $pair,
        #[OA\Property(type: 'array', items: new OA\Items(ref: new Model(type: RatePoint::class)))]
        public array $points,
    ) {
    }
}
