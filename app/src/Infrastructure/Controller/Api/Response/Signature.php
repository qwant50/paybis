<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use OpenApi\Attributes as OA;

/**
 * The {@code security} block of the {@see ApiEnvelope}: an integrity signature
 * over the response payload.
 *
 * Lets a client verify that the {@code data} (or {@code error}) it received was
 * produced by this server and not altered in transit — the meaningful guarantee
 * for a financial rates feed. The signature is an HMAC over the *canonical* JSON
 * of the payload only (see {@see \App\Infrastructure\Controller\Api\Security\ResponseSigner}).
 * {@see $keyId} names the secret used, so keys can be rotated without breaking
 * verification of in-flight responses.
 */
#[OA\Schema(schema: 'Signature')]
final readonly class Signature
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'HMAC-SHA256')]
        public string $algorithm,
        #[OA\Property(type: 'string', description: 'Identifier of the signing key (supports rotation).', example: 'v1')]
        public string $keyId,
        #[OA\Property(type: 'string', description: 'Lowercase hex HMAC over the canonical payload JSON.', example: '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08')]
        public string $signature,
    ) {
    }
}
