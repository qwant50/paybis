<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiError;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RatePoint;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RateSeriesResponse;
use App\Infrastructure\Logging\CorrelationContext;
use Codeception\Test\Unit;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApiResponderTest extends Unit
{
    private const string SEED = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public function testOkBuildsASignedSuccessEnvelope(): void
    {
        $data = new RateSeriesResponse('EUR/BTC', [new RatePoint('2026-06-07T00:00:00+00:00', '52878.09')]);

        $body = $this->decode(
            $this->responder('/api/v1/rates/last-24h', '01REQUESTID')->ok($data),
        );

        $this->assertSame('01REQUESTID', $body['id']);
        $this->assertSame('success', $body['status']);
        $this->assertSame(['api' => 'v1', 'release' => '1.0.0'], $body['version']);
        $this->assertSame('2026-06-07T12:34:56+00:00', $body['datetime']);
        $this->assertSame('EUR/BTC', $body['data']['pair']);
        $this->assertArrayNotHasKey('error', $body);

        $this->assertSame('Ed25519', $body['security']['algorithm']);
        $this->assertTrue($this->signatureVerifies($body, $data));
    }

    public function testErrorBuildsASignedErrorEnvelope(): void
    {
        $error = new ApiError('Unsupported currency pair.', 'INVALID_PAIR');

        $body = $this->decode(
            $this->responder('/api/v1/rates/last-24h', '01REQUESTID')->error($error, 400),
        );

        $this->assertSame('error', $body['status']);
        $this->assertSame('Unsupported currency pair.', $body['error']['message']);
        $this->assertSame('INVALID_PAIR', $body['error']['code']);
        $this->assertArrayNotHasKey('data', $body);

        $this->assertSame('Ed25519', $body['security']['algorithm']);
        $this->assertTrue($this->signatureVerifies($body, $error));
    }

    public function testItDerivesTheApiVersionFromTheRequestPath(): void
    {
        $body = $this->decode(
            $this->responder('/api/v2/rates/last-24h', '01REQUESTID')->ok(new ApiError('x', 'Y')),
        );

        $this->assertSame('v2', $body['version']['api']);
    }

    public function testItGeneratesAUlidWhenNoCorrelationIdIsSet(): void
    {
        $body = $this->decode(
            $this->responder('/api/v1/rates/last-24h', null)->ok(new ApiError('x', 'Y')),
        );

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $body['id']);
    }

    private function responder(string $path, ?string $requestId): ApiResponder
    {
        $stack = new RequestStack();
        $stack->push(Request::create($path));

        $context = new CorrelationContext();
        if ($requestId !== null) {
            $context->set($requestId);
        }

        return new ApiResponder(
            $stack,
            $context,
            new MockClock('2026-06-07T12:34:56+00:00'),
            new ResponseSigner(self::SEED, 'test'),
            '1.0.0',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Symfony\Component\HttpFoundation\JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Verifies the envelope's Ed25519 signature against the public key — the
     * client's perspective — over the canonical {id, datetime, version, payload}
     * composite, proving it binds the freshness metadata, not the payload alone.
     *
     * @param array<string, mixed> $body
     */
    private function signatureVerifies(array $body, object $payload): bool
    {
        $publicKey = sodium_crypto_sign_publickey_from_secretkey(
            sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(sodium_hex2bin(self::SEED))),
        );

        return sodium_crypto_sign_verify_detached(
            sodium_hex2bin($body['security']['signature']),
            (string) json_encode(
                [
                    'id' => $body['id'],
                    'datetime' => $body['datetime'],
                    'version' => $body['version'],
                    'payload' => $payload,
                ],
                ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR,
            ),
            $publicKey,
        );
    }
}
