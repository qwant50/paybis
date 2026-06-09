<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Api\V1\Rate\Action;

use App\Application\ExchangeRate\Query\RateQueryService;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Infrastructure\Controller\Api\ApiResponder;
use App\Infrastructure\Controller\Api\Response\ApiErrorEnvelope;
use App\Infrastructure\Controller\Api\V1\Rate\Mapper\RateSeriesMapper;
use App\Infrastructure\Controller\Api\V1\Rate\Response\RateSeriesEnvelope;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/v1/rates/last-24h — rolling 24h EUR→crypto series for charting.
 *
 * Invalid input is raised as a domain exception ({@see CurrencyPair}) and turned
 * into the JSON error envelope centrally by
 * {@see \App\Infrastructure\EventListener\ApiExceptionListener}.
 */
final class LastDayAction
{
    public function __construct(
        private readonly RateQueryService $rateQuery,
        private readonly RateSeriesMapper $mapper,
        private readonly ApiResponder $responder,
    ) {
    }

    #[Route('/api/v1/rates/last-24h', name: 'api_v1_rates_last_24h', methods: ['GET'])]
    #[OA\Get(
        summary: 'Rates for the last 24 hours (one sample every 5 minutes).',
        parameters: [
            new OA\Parameter(
                name: 'pair',
                description: 'Currency pair.',
                in: 'query',
                required: true,
                schema: new OA\Schema(type: 'string', enum: CurrencyPair::SUPPORTED),
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Rate series.',
                content: new OA\JsonContent(ref: new Model(type: RateSeriesEnvelope::class)),
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid pair.',
                content: new OA\JsonContent(ref: new Model(type: ApiErrorEnvelope::class)),
            ),
            new OA\Response(
                response: 500,
                description: 'Internal server error.',
                content: new OA\JsonContent(ref: new Model(type: ApiErrorEnvelope::class)),
            ),
        ],
    )]
    public function __invoke(CurrencyPair $pair): JsonResponse
    {
        $response = $this->responder->ok($this->mapper->toResponse($pair, $this->rateQuery->lastDay($pair)));

        // A fresh sample lands every 5 minutes; a short shared TTL spares the API
        // a thundering chart client without ever serving truly stale data.
        $response->setPublic();
        $response->setMaxAge(60);

        return $response;
    }
}
