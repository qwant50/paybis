<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use Tests\Support\IntegrationTester;

final class RateApiCest
{
    public function last24hReturnsStoredPoints(IntegrationTester $I): void
    {
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/BTC', '52878.09000000', new \DateTimeImmutable('-1 hour')));
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/ETH', '1357.96000000', new \DateTimeImmutable('-1 hour')));

        $I->amOnPage('/api/v1/rates/last-24h?pair=EUR/BTC');
        $I->seeResponseCodeIs(200);

        $body = $this->json($I);
        $this->assertSuccessEnvelope($I, $body);
        $this->assertSignatureMatches($I, $body['data'], $body['security']['signature']);

        $I->assertSame('EUR/BTC', $body['data']['pair']);
        $I->assertCount(1, $body['data']['points']);
        $I->assertSame('52878.09', $body['data']['points'][0]['price']);
    }

    public function last24hExcludesOlderSamples(IntegrationTester $I): void
    {
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/BTC', '11111.00000000', new \DateTimeImmutable('-2 days')));

        $I->amOnPage('/api/v1/rates/last-24h?pair=EUR/BTC');
        $I->seeResponseCodeIs(200);

        $body = $this->json($I);
        $this->assertSuccessEnvelope($I, $body);
        $I->assertCount(0, $body['data']['points']);
    }

    public function dayReturnsOnlyThatDay(IntegrationTester $I): void
    {
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/LTC', '36.87000000', new \DateTimeImmutable('2026-03-15 10:00:00', new \DateTimeZone('UTC'))));
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/LTC', '99.99000000', new \DateTimeImmutable('2026-03-16 10:00:00', new \DateTimeZone('UTC'))));

        $I->amOnPage('/api/v1/rates/day?pair=EUR/LTC&date=2026-03-15');
        $I->seeResponseCodeIs(200);

        $body = $this->json($I);
        $this->assertSuccessEnvelope($I, $body);
        $I->assertCount(1, $body['data']['points']);
        $I->assertSame('36.87', $body['data']['points'][0]['price']);
    }

    public function unknownPairReturns400(IntegrationTester $I): void
    {
        $I->amOnPage('/api/v1/rates/last-24h?pair=EUR/DOGE');
        $I->seeResponseCodeIs(400);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('INVALID_PAIR', $body['error']['code']);
        $I->assertNotEmpty($body['error']['message']);
    }

    public function invalidDateReturns400(IntegrationTester $I): void
    {
        $I->amOnPage('/api/v1/rates/day?pair=EUR/BTC&date=not-a-date');
        $I->seeResponseCodeIs(400);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('INVALID_DATE', $body['error']['code']);
    }

    public function unknownRouteReturns404(IntegrationTester $I): void
    {
        $I->amOnPage('/api/v1/rates/does-not-exist');
        $I->seeResponseCodeIs(404);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('NOT_FOUND', $body['error']['code']);
    }

    public function wrongMethodReturns405(IntegrationTester $I): void
    {
        $I->sendAjaxPostRequest('/api/v1/rates/last-24h?pair=EUR/BTC');
        $I->seeResponseCodeIs(405);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('METHOD_NOT_ALLOWED', $body['error']['code']);
    }

    public function emptyResultReturnsEmptyPoints(IntegrationTester $I): void
    {
        $I->amOnPage('/api/v1/rates/day?pair=EUR/BTC&date=2000-01-01');
        $I->seeResponseCodeIs(200);

        $body = $this->json($I);
        $this->assertSuccessEnvelope($I, $body);
        $I->assertSame('EUR/BTC', $body['data']['pair']);
        $I->assertSame([], $body['data']['points']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertSuccessEnvelope(IntegrationTester $I, array $body): void
    {
        $this->assertEnvelopeMeta($I, $body);
        $I->assertSame('success', $body['status']);
        $I->assertArrayHasKey('data', $body);
        $I->assertArrayNotHasKey('error', $body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertErrorEnvelope(IntegrationTester $I, array $body): void
    {
        $this->assertEnvelopeMeta($I, $body);
        $I->assertSame('error', $body['status']);
        $I->assertArrayHasKey('error', $body);
        $I->assertArrayNotHasKey('data', $body);
    }

    /**
     * Shared envelope invariants: id matches the X-Request-Id header, version,
     * datetime, and a present HMAC signature.
     *
     * @param array<string, mixed> $body
     */
    private function assertEnvelopeMeta(IntegrationTester $I, array $body): void
    {
        $I->assertNotEmpty($body['id']);
        $I->assertResponseHeaderSame('X-Request-Id', $body['id']);
        $I->assertSame(['api' => 'v1', 'release' => '1.0.0'], $body['version']);
        $I->assertNotEmpty($body['datetime']);
        $I->assertSame('HMAC-SHA256', $body['security']['algorithm']);
        $I->assertSame('test', $body['security']['keyId']);
        $I->assertNotEmpty($body['security']['signature']);
    }

    /**
     * Recomputes the HMAC over the canonical payload to prove the signature is
     * verifiable client-side with the shared secret.
     *
     * @param array<string, mixed> $payload
     */
    private function assertSignatureMatches(IntegrationTester $I, array $payload, string $signature): void
    {
        $expected = hash_hmac(
            'sha256',
            (string) json_encode($payload, ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR),
            'test_signing_secret',
        );

        $I->assertSame($expected, $signature);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(IntegrationTester $I): array
    {
        return json_decode($I->grabPageSource(), true, 512, JSON_THROW_ON_ERROR);
    }
}
