<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Health\Response;

use OpenApi\Attributes as OA;

/**
 * The JSON payload returned by the health endpoint when the service is healthy.
 *
 * Public properties define the wire contract. Unhealthy states are not returned
 * as this payload — they surface as a 503 error envelope (see
 * {@see \App\Infrastructure\Controller\Api\V1\Health\Action\HealthAction}).
 */
#[OA\Schema(schema: 'HealthResponse')]
final readonly class HealthResponse
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'healthy')]
        public string $status,
        #[OA\Property(type: 'string', format: 'date-time', example: '2026-06-08T12:00:00+00:00')]
        public string $lastSampleAt,
        #[OA\Property(type: 'integer', description: 'Age of the most recent sample, in seconds.', example: 42)]
        public int $sampleAgeSeconds,
    ) {
    }
}
