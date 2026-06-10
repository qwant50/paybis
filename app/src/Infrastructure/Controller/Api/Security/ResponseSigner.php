<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Security;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\Signature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Computes the {@see Signature} carried in every {@see \App\Infrastructure\Controller\Api\Response\ApiEnvelope}.
 *
 * The signature is an HMAC-SHA256 over the *canonical* JSON of the payload only —
 * the {@code data} object on success, the {@code error} object on failure — never
 * the per-response metadata (id, datetime, version). The goal is to attest the
 * authenticity of the rates payload, which is the security property that matters
 * for this feed.
 *
 * Canonical form is {@code json_encode($payload, ApiResponder::ENCODING_OPTIONS)}:
 * UTF-8, slashes/unicode unescaped, no insignificant whitespace, and the property
 * order fixed by the response DTOs. {@see ApiResponder} encodes the whole envelope
 * with the same options, so the {@code data}/{@code error} bytes on the wire are
 * exactly what was signed — a client re-encoding that sub-object with the same
 * rules reproduces the HMAC. {@see $keyId} names the secret, enabling rotation.
 */
final readonly class ResponseSigner
{
    public function __construct(
        #[Autowire(env: 'API_SIGNING_SECRET')]
        private string $secret,
        #[Autowire(env: 'API_SIGNING_KEY_ID')]
        private string $keyId,
    ) {
    }

    public function sign(object $payload): Signature
    {
        $canonical = json_encode($payload, ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR);

        return new Signature(
            'HMAC-SHA256',
            $this->keyId,
            hash_hmac('sha256', $canonical, $this->secret),
        );
    }
}
