<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Infrastructure\Controller\Api\ApiResponder;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tags every log record with the current request's correlation id.
 *
 * Reads the id assigned by {@see \App\Infrastructure\EventListener\RequestIdListener}
 * and copies it into {@code extra.request_id}, so file logs can be filtered down
 * to a single request — the same id a client sees in the {@code X-Request-Id}
 * header and the response envelope's {@code id}. CLI/worker logs (no HTTP request)
 * simply carry no id.
 */
#[AsMonologProcessor]
final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->requestStack->getCurrentRequest()
            ?->attributes->get(ApiResponder::REQUEST_ID_ATTRIBUTE);

        if (is_string($id) && $id !== '') {
            $record->extra['request_id'] = $id;
        }

        return $record;
    }
}
