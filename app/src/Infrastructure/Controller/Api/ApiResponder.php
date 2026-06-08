<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api;

use App\Infrastructure\Controller\Api\Response\ApiEnvelope;
use App\Infrastructure\Controller\Api\Response\ApiError;
use App\Infrastructure\Controller\Api\Response\ApiVersion;
use App\Infrastructure\Controller\Api\Security\ResponseSigner;
use App\Infrastructure\Logging\CorrelationContext;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared JSON responder for every API response (all versions, all resources).
 *
 * The single place that wraps a payload in the consistent {@see ApiEnvelope} and
 * turns it into an HTTP response — for both action success bodies ({@see ok()})
 * and the exception listener's errors ({@see error()}). It stamps every response
 * with the per-request correlation id — both as the envelope's {@code id} and the
 * {@see self::REQUEST_ID_HEADER} header, set here (not via a kernel.response
 * listener, which is skipped on some exception paths) so the two can never drift
 * — plus the {@see ApiVersion}, a UTC timestamp, and the {@see ResponseSigner}
 * integrity signature, so success and error share one top-level shape and the
 * metadata is produced in exactly one place.
 *
 * The {@code data}/{@code error} payload itself is a response DTO whose public
 * properties define the wire contract; resource-specific shaping belongs in that
 * resource's mapper, not here.
 */
final readonly class ApiResponder
{
    /**
     * Leave slashes and unicode unescaped so values like "EUR/BTC" travel as
     * "EUR/BTC" rather than "EUR\/BTC" — valid JSON either way, but cleaner on
     * the wire and in the docs. Public because {@see ResponseSigner} must
     * canonicalize the payload with the exact same options used to serialize it.
     */
    public const int ENCODING_OPTIONS = JsonResponse::DEFAULT_ENCODING_OPTIONS
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE;

    /**
     * Response header carrying the correlation id back to the client, mirroring
     * the envelope's {@code id} for log/trace correlation.
     */
    public const string REQUEST_ID_HEADER = 'X-Request-Id';

    public function __construct(
        private RequestStack $requestStack,
        private CorrelationContext $correlation,
        private ClockInterface $clock,
        private ResponseSigner $signer,
        #[Autowire('%app.release%')]
        private string $release,
    ) {
    }

    public function ok(object $data): JsonResponse
    {
        $id = $this->requestId();

        $envelope = ApiEnvelope::success($id, $this->version(), $this->now(), $data, $this->signer->sign($data));

        return $this->json($envelope, $id, Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $headers
     * @throws \JsonException
     */
    public function error(ApiError $error, int $status, array $headers = []): JsonResponse
    {
        $id = $this->requestId();

        $envelope = ApiEnvelope::failure($id, $this->version(), $this->now(), $error, $this->signer->sign($error));

        return $this->json($envelope, $id, $status, $headers);
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function json(ApiEnvelope $envelope, string $id, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($envelope, $status, $headers);
        $response->setEncodingOptions(self::ENCODING_OPTIONS);
        $response->headers->set(self::REQUEST_ID_HEADER, $id);

        return $response;
    }

    private function requestId(): string
    {
        // Normally set early by RequestIdListener; getOrGenerate falls back to a
        // fresh ULID so a response is never left without a correlation id (e.g. in
        // isolated unit tests).
        return $this->correlation->getOrGenerate();
    }

    private function version(): ApiVersion
    {
        $path = $this->requestStack->getCurrentRequest()?->getPathInfo() ?? '';
        $api = preg_match('#^/api/(v\d+)#', $path, $m) === 1 ? $m[1] : 'v1';

        return new ApiVersion($api, $this->release);
    }

    private function now(): string
    {
        return $this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }
}
