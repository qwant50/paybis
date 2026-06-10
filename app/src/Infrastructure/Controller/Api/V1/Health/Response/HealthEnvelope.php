<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Health\Response;

use App\Infrastructure\Controller\Api\Response\ApiEnvelope;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * OpenAPI schema for a healthy response: the shared {@see ApiEnvelope} with its
 * {@code data} payload set to a {@see HealthResponse}.
 *
 * Documentation only — there is no runtime instance; the action returns the live
 * {@see ApiEnvelope} via {@see \App\Infrastructure\Controller\Api\ApiResponder::ok()}.
 */
#[OA\Schema(
    schema: 'HealthEnvelope',
    allOf: [
        new OA\Schema(ref: new Model(type: ApiEnvelope::class)),
        new OA\Schema(
            required: ['data'],
            properties: [
                new OA\Property(property: 'data', ref: new Model(type: HealthResponse::class)),
            ],
        ),
    ],
)]
final class HealthEnvelope
{
}
