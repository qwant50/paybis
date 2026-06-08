<?php

declare(strict_types=1);

namespace App\Application\Service;

/**
 * Port for emitting operational metrics (counters and timings), kept in the
 * Application layer so services depend on behaviour, not a concrete backend.
 *
 * The shipped adapter writes structured records to a dedicated log channel
 * ({@see \App\Infrastructure\Observability\LoggingMetrics}); it can be swapped for
 * StatsD/Prometheus later without touching call sites. Tags are low-cardinality
 * dimensions (e.g. {@code pair}, {@code outcome}) — never unbounded values.
 */
interface Metrics
{
    /**
     * Add to a counter (default +1).
     *
     * @param array<string, string> $tags
     */
    public function increment(string $name, int $value = 1, array $tags = []): void;

    /**
     * Record a duration in milliseconds.
     *
     * @param array<string, string> $tags
     */
    public function timing(string $name, float $milliseconds, array $tags = []): void;
}
