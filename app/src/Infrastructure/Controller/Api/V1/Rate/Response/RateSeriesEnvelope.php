<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Response;

use App\Infrastructure\Controller\Api\Response\ApiEnvelope;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * OpenAPI schema for a successful rate-series response: the shared
 * {@see ApiEnvelope} with its {@code data} payload set to a {@see RateSeriesResponse}.
 *
 * Documentation only — there is no runtime instance; the action returns the live
 * {@see ApiEnvelope} via {@see \App\Infrastructure\Controller\Api\ApiResponder::ok()}.
 * Composing the generic envelope with the resource payload here keeps the action
 * attributes scannable.
 */
#[OA\Schema(
    schema: 'RateSeriesEnvelope',
    allOf: [
        new OA\Schema(ref: new Model(type: ApiEnvelope::class)),
        new OA\Schema(
            required: ['data'],
            properties: [
                new OA\Property(property: 'data', ref: new Model(type: RateSeriesResponse::class)),
            ],
        ),
    ],
)]
final class RateSeriesEnvelope
{
}
