<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api\Security;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiError;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use Codeception\Test\Unit;

final class ResponseSignerTest extends Unit
{
    public function testItHmacsTheCanonicalPayloadJson(): void
    {
        $payload = new ApiError('boom', 'CODE');
        $signature = (new ResponseSigner('secret', 'v1'))->sign($payload);

        $expected = hash_hmac(
            'sha256',
            (string) json_encode($payload, ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR),
            'secret',
        );

        $this->assertSame('HMAC-SHA256', $signature->algorithm);
        $this->assertSame('v1', $signature->keyId);
        $this->assertSame($expected, $signature->signature);
    }

    public function testItIsDeterministicForTheSameInput(): void
    {
        $signer = new ResponseSigner('secret', 'v1');
        $payload = new ApiError('boom', 'CODE');

        $this->assertSame($signer->sign($payload)->signature, $signer->sign($payload)->signature);
    }

    public function testADifferentSecretYieldsADifferentSignature(): void
    {
        $payload = new ApiError('boom', 'CODE');

        $this->assertNotSame(
            (new ResponseSigner('secret-a', 'v1'))->sign($payload)->signature,
            (new ResponseSigner('secret-b', 'v1'))->sign($payload)->signature,
        );
    }
}
