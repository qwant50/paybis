<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use OpenApi\Attributes as OA;

/**
 * The {@code version} block of the {@see ApiEnvelope}.
 *
 * Splits "which API contract" from "which build of it": {@see $api} is the URL
 * contract version (e.g. {@code v1}, derived from the request path) and changes
 * only on a breaking redesign, while {@see $release} is the deployed application
 * version (the OpenAPI {@code info.version}), bumped on every release. A client
 * can pin to {@see $api} and still observe {@see $release} for diagnostics.
 */
#[OA\Schema(schema: 'ApiVersion')]
final readonly class ApiVersion
{
    public function __construct(
        #[OA\Property(type: 'string', description: 'URL contract version (path prefix).', example: 'v1')]
        public string $api,
        #[OA\Property(type: 'string', description: 'Deployed application release.', example: '1.0.0')]
        public string $release,
    ) {
    }
}
