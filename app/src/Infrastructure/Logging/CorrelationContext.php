<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Symfony\Component\Uid\Ulid;

/**
 * Holds the correlation id for the current unit of work — one HTTP request or one
 * worker message — so log records, the response envelope's {@code id}, and the
 * {@code X-Request-Id} header all share a single value.
 *
 * A mutable, shared service: set once per request by
 * {@see \App\Infrastructure\EventListener\RequestIdListener} and once per message
 * by {@see \App\Infrastructure\Scheduler\FetchRatesMessageHandler}. The
 * long-running messenger worker reuses the same instance across messages, so each
 * handler run sets a fresh id. Decoupling correlation from the HTTP RequestStack is
 * what lets worker/backfill runs carry a traceable id, which they previously lacked.
 */
final class CorrelationContext
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    /** The current id, or null if none has been set yet (e.g. an unscoped CLI command). */
    public function current(): ?string
    {
        return $this->id;
    }

    /** The current id, minting and storing a ULID if none has been set yet. */
    public function getOrGenerate(): string
    {
        return $this->id ??= (string) new Ulid();
    }
}
