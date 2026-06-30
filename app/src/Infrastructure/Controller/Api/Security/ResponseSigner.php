<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\Security;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiVersion;
use App\Infrastructure\Controller\Api\Response\Signature;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Computes the {@see Signature} carried in every {@see \App\Infrastructure\Controller\Api\Response\ApiEnvelope}.
 *
 * The signature is an Ed25519 detached signature over the *canonical* JSON of a
 * composite that binds the payload to the context that makes it meaningful: the
 * correlation {@code id}, the {@code datetime} the response was produced, the API
 * {@code version}, and the {@code payload} itself (the {@code data} object on
 * success, the {@code error} object on failure).
 *
 * Ed25519 is *asymmetric*: the server signs with a private key, and a client
 * verifies with the matching {@see publicKeyHex() public key}. Holding the public
 * key grants verification but **not** the ability to forge — so the verify
 * capability can be handed to untrusted or public consumers without letting them
 * mint responses (the property HMAC, with its shared secret, cannot offer).
 *
 * Binding {@code datetime} gives the signature *freshness*: a captured
 * {@code (payload, signature)} pair cannot be replayed under a forged current
 * timestamp, because the timestamp the client trusts is inside the signed bytes.
 * Binding {@code id}/{@code version} stops a payload signed for one request or
 * contract version being presented as the answer to another.
 *
 * Canonical form is {@code json_encode([...], ApiResponder::ENCODING_OPTIONS)}
 * over a fixed key order ({@code id, datetime, version, payload}): UTF-8,
 * slashes/unicode unescaped, no insignificant whitespace, property order fixed by
 * the DTOs. A client reconstructs the same composite from the envelope's
 * {@code id}/{@code datetime}/{@code version} and the {@code data}/{@code error}
 * sub-object, re-encodes it with the same rules, and verifies the signature with
 * the public key for the named {@see $keyId} — confirming authenticity *and*
 * freshness. {@see $keyId} names the key, so it can be rotated (publish a new key
 * under a new id, sign with it, retire the old once clients refresh).
 */
final readonly class ResponseSigner
{
    private string $secretKey;

    /**
     * @param string $privateKeyHex Hex-encoded 32-byte Ed25519 seed (the private
     *                              signing key). The keypair derives deterministically
     *                              from it; generate once and keep it secret.
     */
    public function __construct(
        #[Autowire(env: 'API_SIGNING_PRIVATE_KEY')]
        string $privateKeyHex,
        #[Autowire(env: 'API_SIGNING_KEY_ID')]
        private string $keyId,
    ) {
        $seed = sodium_hex2bin($privateKeyHex);

        if (\strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException(\sprintf(
                'API_SIGNING_PRIVATE_KEY must be a hex-encoded %d-byte Ed25519 seed; decoded to %d bytes.',
                SODIUM_CRYPTO_SIGN_SEEDBYTES,
                \strlen($seed),
            ));
        }

        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        sodium_memzero($seed);
        sodium_memzero($keypair);
    }

    public function sign(object $payload, string $id, string $datetime, ApiVersion $version): Signature
    {
        $canonical = json_encode(
            ['id' => $id, 'datetime' => $datetime, 'version' => $version, 'payload' => $payload],
            ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR,
        );

        $signature = sodium_crypto_sign_detached($canonical, $this->secretKey);

        return new Signature('Ed25519', $this->keyId, sodium_bin2hex($signature));
    }

    /**
     * The public verification key (hex) derived from the configured private key.
     *
     * Non-secret and safe to publish — this is what clients use to verify a
     * signature for {@see $keyId} (e.g. served from a key-distribution endpoint).
     */
    public function publicKeyHex(): string
    {
        return sodium_bin2hex(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }
}
