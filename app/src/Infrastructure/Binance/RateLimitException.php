<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

/**
 * Binance rejected a request because of rate limiting (HTTP 429, or 418 once the
 * IP is auto-banned for ignoring 429s).
 *
 * Distinct from the generic {@see \RuntimeException} so resilience code can treat
 * it differently: fast in-run retries during a rate-limit event add load exactly
 * when Binance asks for less and escalate toward the 418 ban, so
 * {@see RetryingPriceHistoryProvider} propagates this immediately instead — the
 * next scheduled run (5 minutes out, far beyond any retry backoff) is the retry.
 * Still a {@see \RuntimeException}, so the {@see \App\Application\ExchangeRate\Service\PriceHistoryProvider}
 * port contract and the fetcher's failure isolation are unchanged.
 */
final class RateLimitException extends \RuntimeException
{
}
