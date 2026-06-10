<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Tags every log record with the current correlation id.
 *
 * Reads the id from the {@see CorrelationContext} — set per request by
 * {@see \App\Infrastructure\EventListener\RequestIdListener} and per worker message
 * by {@see \App\Infrastructure\Scheduler\FetchRatesMessageHandler} — and copies it
 * into {@code extra.request_id}, so file logs can be filtered down to a single
 * request *or worker run*: the same id a client sees in the {@code X-Request-Id}
 * header and the response envelope's {@code id}. Records emitted outside any scope
 * (e.g. an unscoped CLI command) simply carry no id.
 */
#[AsMonologProcessor]
final readonly class RequestIdProcessor
{
    public function __construct(
        private CorrelationContext $correlation,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->correlation->current();

        if ($id !== null) {
            $record->extra['request_id'] = $id;
        }

        return $record;
    }
}
