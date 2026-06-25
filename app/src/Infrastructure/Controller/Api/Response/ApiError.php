<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use OpenApi\Attributes as OA;

/**
 * The {@code error} payload nested inside the {@see ApiEnvelope} on failures
 * ({@code {"message": "...", "code": "..."}}).
 *
 * Single source of truth for the error wire shape: built at runtime from a
 * caught exception by {@see \App\Infrastructure\EventListener\ApiExceptionListener},
 * wrapped + signed by {@see \App\Infrastructure\Controller\Api\ApiResponder::error()},
 * and — via the OpenAPI attributes below — referenced as the {@code error} schema
 * by every endpoint's 4xx/5xx response, so the documented and emitted bodies
 * cannot drift. {@see $code} is the stable, machine-readable discriminator;
 * {@see $message} is human-readable and client-safe.
 */
#[OA\Schema(schema: 'ApiError')]
final readonly class ApiError
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'Unsupported currency pair "EUR/DOGE". Supported pairs: EUR/BTC, EUR/ETH, EUR/LTC.')]
        public string $message,
        #[OA\Property(type: 'string', description: 'Stable machine-readable error code.', example: 'INVALID_PAIR')]
        public string $code,
    ) {
    }
}
