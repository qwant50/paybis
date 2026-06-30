<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Health\Action;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\RateRepository;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiErrorEnvelope;
use App\Infrastructure\Controller\Api\V1\Health\Response\HealthEnvelope;
use App\Infrastructure\Controller\Api\V1\Health\Response\HealthResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/v1/health — readiness probe for the rates feed.
 *
 * Reports 200 only when the database is reachable AND every supported pair has a
 * recent sample; any failure (DB unreachable, a pair with no samples yet, or a
 * pair gone stale) is raised as a 503 so an orchestrator/load balancer can act on
 * the status code. Freshness is checked *per pair* — a cross-pair latest would
 * stay fresh while a single dead pair (delisted symbol, persistent fetch failure)
 * went unnoticed behind the others. The shared
 * {@see \App\Infrastructure\EventListener\ApiExceptionListener} turns the thrown
 * {@see ServiceUnavailableHttpException} into the standard error envelope; the
 * specific cause is logged here for operators rather than leaked to clients.
 *
 * The single try/catch is intentional: unlike the input-validation actions (which
 * let domain exceptions propagate), detecting an unreachable dependency is this
 * probe's whole job.
 */
final class HealthAction
{
    /**
     * Data is stale once it predates this many seconds — three missed 5-minute
     * ticks. Past this, the scheduler/worker is assumed broken even if the DB is up.
     */
    private const int STALE_AFTER_SECONDS = 900;

    public function __construct(
        private readonly RateRepository $rates,
        private readonly ClockInterface $clock,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/health', name: 'api_v1_health', methods: ['GET'])]
    #[OA\Get(
        summary: 'Service readiness: database reachability and feed freshness.',
        responses: [
            new OA\Response(
                response: 200,
                description: 'Service is healthy.',
                content: new OA\JsonContent(ref: new Model(type: HealthEnvelope::class)),
            ),
            new OA\Response(
                response: 503,
                description: 'Service is unavailable (database unreachable, no samples, or stale data).',
                content: new OA\JsonContent(ref: new Model(type: ApiErrorEnvelope::class)),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        try {
            $latestByPair = [];
            foreach (CurrencyPair::all() as $pair) {
                $latestByPair[$pair->value()] = $this->rates->latestRecordedAt($pair);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Health check failed: database unreachable.', ['exception' => $e]);

            throw new ServiceUnavailableHttpException(message: 'Service unavailable.', previous: $e);
        }

        $now = $this->clock->now();
        $oldest = null;
        foreach ($latestByPair as $pairValue => $latest) {
            if ($latest === null) {
                $this->logger->warning('Health check failed: pair has no rate samples stored yet.', [
                    'pair' => $pairValue,
                ]);

                throw new ServiceUnavailableHttpException(message: 'Service unavailable.');
            }

            $ageSeconds = $now->getTimestamp() - $latest->getTimestamp();
            if ($ageSeconds > self::STALE_AFTER_SECONDS) {
                $this->logger->warning('Health check failed: pair rate data is stale.', [
                    'pair'           => $pairValue,
                    'last_sample_at' => $latest->format(\DateTimeInterface::ATOM),
                    'age_seconds'    => $ageSeconds,
                ]);

                throw new ServiceUnavailableHttpException(message: 'Service unavailable.');
            }

            if ($oldest === null || $latest < $oldest) {
                $oldest = $latest;
            }
        }

        if ($oldest === null) {
            // Unreachable while CurrencyPair::MAP is non-empty; guards the type.
            throw new ServiceUnavailableHttpException(message: 'Service unavailable.');
        }

        // The reported sample is the *worst* pair's latest — the figure that
        // drives staleness, so operators see the closest-to-failing pair.
        return $this->responder->ok(new HealthResponse(
            status: 'healthy',
            lastSampleAt: $oldest->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            sampleAgeSeconds: $now->getTimestamp() - $oldest->getTimestamp(),
        ));
    }
}
