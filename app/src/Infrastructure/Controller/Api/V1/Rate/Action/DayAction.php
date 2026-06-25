<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Action;

use App\Application\ExchangeRate\Query\RateQueryService;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\Day;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiErrorEnvelope;
use App\Infrastructure\Controller\Api\V1\Rate\Mapper\RateSeriesMapper;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RateSeriesEnvelope;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/v1/rates/day — EUR→crypto series for a specific UTC day.
 *
 * Invalid input is raised as a domain exception ({@see CurrencyPair}, {@see Day})
 * and turned into the JSON error envelope centrally by
 * {@see \App\Infrastructure\EventListener\ApiExceptionListener}.
 */
final class DayAction
{
    public function __construct(
        private readonly RateQueryService $rateQuery,
        private readonly RateSeriesMapper $mapper,
        private readonly ApiResponder $responder,
    ) {
    }

    #[Route('/api/v1/rates/day', name: 'api_v1_rates_day', methods: ['GET'])]
    #[OA\Get(
        summary: 'Rates for a specific UTC day (one sample every 5 minutes).',
        parameters: [
            new OA\Parameter(
                name: 'pair',
                description: 'Currency pair.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: CurrencyPair::SUPPORTED),
            ),
            new OA\Parameter(
                name: 'date',
                description: 'Day in YYYY-MM-DD format (UTC).',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', format: 'date', example: '2026-06-06'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rate series.',
                content: new OA\JsonContent(ref: new Model(type: RateSeriesEnvelope::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid pair or date.',
                content: new OA\JsonContent(ref: new Model(type: ApiErrorEnvelope::class)),
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error.',
                content: new OA\JsonContent(ref: new Model(type: ApiErrorEnvelope::class)),
            ),
        ],
    )]
    public function __invoke(CurrencyPair $pair, Day $day): JsonResponse
    {
        $response = $this->responder->ok(
            $this->mapper->toResponse($pair, $this->rateQuery->forDay($pair, $day->toDateTime())),
        );

        $response->setPublic();

        // A fully-elapsed UTC day is final and can be cached hard; a day still in
        // progress keeps gaining samples, so it gets the same short TTL as last-24h.
        $end = $day->toDateTime()->add(new \DateInterval('P1D'));
        if ($end <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            $response->setMaxAge(86400);
            $response->setImmutable();
        } else {
            $response->setMaxAge(60);
        }

        return $response;
    }
}
