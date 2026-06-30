<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Controller\Api\Security;

use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiError;
use App\Infrastructure\Controller\Api\Response\ApiVersion;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use Codeception\Test\Unit;

final class ResponseSignerTest extends Unit
{
    private const string SEED = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string OTHER_SEED = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';
    private const string ID = '01REQUESTID';
    private const string DATETIME = '2026-06-07T12:34:56+00:00';

    public function testItSignsTheCanonicalCompositeVerifiablyWithThePublicKey(): void
    {
        $payload = new ApiError('boom', 'CODE');
        $version = new ApiVersion('v1', '1.0.0');
        $signer = new ResponseSigner(self::SEED, 'v1');

        $signature = $signer->sign($payload, self::ID, self::DATETIME, $version);

        $this->assertSame('Ed25519', $signature->algorithm);
        $this->assertSame('v1', $signature->keyId);
        $this->assertTrue(
            sodium_crypto_sign_verify_detached(
                sodium_hex2bin($signature->signature),
                $this->canonical($payload, self::DATETIME, $version),
                sodium_hex2bin($signer->publicKeyHex()),
            ),
        );
    }

    public function testItIsDeterministicForTheSameInput(): void
    {
        $signer = new ResponseSigner(self::SEED, 'v1');
        $payload = new ApiError('boom', 'CODE');
        $version = new ApiVersion('v1', '1.0.0');

        $this->assertSame(
            $signer->sign($payload, self::ID, self::DATETIME, $version)->signature,
            $signer->sign($payload, self::ID, self::DATETIME, $version)->signature,
        );
    }

    public function testADifferentDatetimeYieldsADifferentSignature(): void
    {
        $signer = new ResponseSigner(self::SEED, 'v1');
        $payload = new ApiError('boom', 'CODE');
        $version = new ApiVersion('v1', '1.0.0');

        $this->assertNotSame(
            $signer->sign($payload, self::ID, self::DATETIME, $version)->signature,
            $signer->sign($payload, self::ID, '2026-06-07T12:39:56+00:00', $version)->signature,
        );
    }

    public function testADifferentKeyYieldsADifferentSignature(): void
    {
        $payload = new ApiError('boom', 'CODE');
        $version = new ApiVersion('v1', '1.0.0');

        $this->assertNotSame(
            (new ResponseSigner(self::SEED, 'v1'))->sign($payload, self::ID, self::DATETIME, $version)->signature,
            (new ResponseSigner(self::OTHER_SEED, 'v1'))->sign($payload, self::ID, self::DATETIME, $version)->signature,
        );
    }

    public function testItDerivesAStablePublicKeyFromTheSeed(): void
    {
        $this->assertSame(
            '207a067892821e25d770f1fba0c47c11ff4b813e54162ece9eb839e076231ab6',
            (new ResponseSigner(self::SEED, 'v1'))->publicKeyHex(),
        );
    }

    public function testItRejectsASeedOfTheWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/32-byte Ed25519 seed/');

        new ResponseSigner(sodium_bin2hex('too-short'), 'v1');
    }

    private function canonical(object $payload, string $datetime, ApiVersion $version): string
    {
        return (string) json_encode(
            ['id' => self::ID, 'datetime' => $datetime, 'version' => $version, 'payload' => $payload],
            ApiResponder::ENCODING_OPTIONS | JSON_THROW_ON_ERROR,
        );
    }
}
