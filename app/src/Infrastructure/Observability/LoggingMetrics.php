<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability;

use App\Application\ExchangeRate\Service\Metrics;
use Psr\Log\LoggerInterface;

/**
 * {@see Metrics} adapter that emits each metric as one structured log record on a
 * dedicated {@code metrics} channel (wired to its own {@code metrics.log}).
 *
 * No new infrastructure: a log pipeline (Loki, ELK, Datadog, …) derives counters
 * and timings from these records, and — because they carry the correlation id like
 * every other record — a run's metrics tie back to its other log lines. The fixed
 * {@code "metric"} message plus the {@code metric}/{@code type}/{@code value}
 * context keys make the stream trivial to parse.
 */
final readonly class LoggingMetrics implements Metrics
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function increment(string $name, int $value = 1, array $tags = []): void
    {
        $this->logger->info('metric', [
            'metric' => $name,
            'type'   => 'counter',
            'value'  => $value,
            'tags'   => $tags,
        ]);
    }

    public function timing(string $name, float $milliseconds, array $tags = []): void
    {
        $this->logger->info('metric', [
            'metric' => $name,
            'type'   => 'timing',
            'value'  => round($milliseconds, 3),
            'tags'   => $tags,
        ]);
    }
}
