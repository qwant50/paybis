<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiError;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RatePoint;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RateSeriesResponse;
use Codeception\Test\Unit;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class ApiResponderTest extends Unit
{
    private const string SECRET = 'test_signing_secret';

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

        $expected = hash_hmac('sha256', (string) json_encode($data, ApiResponder::ENCODING_OPTIONS), self::SECRET);
        $this->assertSame($expected, $body['security']['signature']);
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

        $expected = hash_hmac('sha256', (string) json_encode($error, ApiResponder::ENCODING_OPTIONS), self::SECRET);
        $this->assertSame($expected, $body['security']['signature']);
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
        $request = Request::create($path);
        if ($requestId !== null) {
            $request->attributes->set(ApiResponder::REQUEST_ID_ATTRIBUTE, $requestId);
        }

        $stack = new RequestStack();
        $stack->push($request);

        return new ApiResponder(
            $stack,
            new MockClock('2026-06-07T12:34:56+00:00'),
            new ResponseSigner(self::SECRET, 'test'),
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
}
