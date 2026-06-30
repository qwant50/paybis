<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;

/**
 * OpenAPI schema for a failed response: the shared {@see ApiEnvelope} with its
 * {@code error} payload set to an {@see ApiError}.
 *
 * Documentation only — there is no runtime instance; {@see ApiResponder::error()}
 * builds the live {@see ApiEnvelope}. Defined once here and referenced by every
 * endpoint's 4xx/5xx response so the documented error shape stays in one place.
 */
#[OA\Schema(
    schema: 'ApiErrorEnvelope',
    allOf: [
        new OA\Schema(ref: new Model(type: ApiEnvelope::class)),
        new OA\Schema(
            required: ['error'],
            properties: [
                new OA\Property(property: 'error', ref: new Model(type: ApiError::class)),
            ],
        ),
    ],
)]
final class ApiErrorEnvelope
{
}
