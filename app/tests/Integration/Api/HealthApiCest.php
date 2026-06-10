<?php

declare(strict_types=1);

namespace Tests\Integration\Api;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Doctrine\Entity\ExchangeRateDoctrine;
use Tests\Support\IntegrationTester;

final class HealthApiCest
{
    public function healthyWhenEveryPairHasARecentSample(IntegrationTester $I): void
    {
        foreach (CurrencyPair::supportedPairs() as $pair) {
            $I->haveInRepository(new ExchangeRateDoctrine($pair, '52878.09000000', new \DateTimeImmutable()));
        }

        $I->amOnPage('/api/v1/health');
        $I->seeResponseCodeIs(200);

        $body = $this->json($I);
        $this->assertSuccessEnvelope($I, $body);
        $this->assertSignatureMatches($I, $body['data'], $body['security']['signature']);

        $I->assertSame('healthy', $body['data']['status']);
        $I->assertNotEmpty($body['data']['lastSampleAt']);
        $I->assertIsInt($body['data']['sampleAgeSeconds']);
        $I->assertLessThan(900, $body['data']['sampleAgeSeconds']);
    }

    public function unavailableWhenOnlyOnePairIsFresh(IntegrationTester $I): void
    {
        // Freshness is per pair: one feeding pair must not mask the dead ones.
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/BTC', '52878.09000000', new \DateTimeImmutable()));

        $I->amOnPage('/api/v1/health');
        $I->seeResponseCodeIs(503);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('SERVICE_UNAVAILABLE', $body['error']['code']);
    }

    public function unavailableWhenTheLatestSampleIsStale(IntegrationTester $I): void
    {
        $I->haveInRepository(new ExchangeRateDoctrine('EUR/BTC', '52878.09000000', new \DateTimeImmutable('-1 hour')));

        $I->amOnPage('/api/v1/health');
        $I->seeResponseCodeIs(503);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('SERVICE_UNAVAILABLE', $body['error']['code']);
    }

    public function unavailableWhenNoSamplesExist(IntegrationTester $I): void
    {
        $I->amOnPage('/api/v1/health');
        $I->seeResponseCodeIs(503);

        $body = $this->json($I);
        $this->assertErrorEnvelope($I, $body);
        $I->assertSame('SERVICE_UNAVAILABLE', $body['error']['code']);
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
