<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Response;

use OpenApi\Attributes as OA;

/**
 * The {@code security} block of the {@see ApiEnvelope}: an integrity signature
 * over the response.
 *
 * Lets a client verify that the {@code data} (or {@code error}) it received was
 * produced by this server, not altered in transit, and is *fresh* — the
 * meaningful guarantee for a financial rates feed. The signature is an Ed25519
 * detached signature over the *canonical* JSON of a composite binding the payload
 * to the response's {@code id}, {@code datetime}, and {@code version}
 * (see {@see \App\Infrastructure\Controller\Api\Security\ResponseSigner}), so a
 * captured response cannot be replayed under a forged timestamp. Verification is
 * asymmetric: a client checks it with the public key for {@see $keyId}, which
 * cannot forge — so {@see $keyId} both names the key and supports rotation.
 */
#[OA\Schema(schema: 'Signature')]
final readonly class Signature
{
    public function __construct(
        #[OA\Property(type: 'string', example: 'Ed25519')]
        public string $algorithm,
        #[OA\Property(type: 'string', description: 'Identifier of the signing key (supports rotation).', example: 'v1')]
        public string $keyId,
        #[OA\Property(type: 'string', description: 'Lowercase hex Ed25519 signature over the canonical JSON of {id, datetime, version, payload}.', example: 'b8f1...e3a9')]
        public string $signature,
    ) {
    }
}
