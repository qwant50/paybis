<?php

declare(strict_types=1);

namespace App\Infrastructure\Binance;

use App\Application\ExchangeRate\Service\PriceHistoryProvider;
use App\Application\ExchangeRate\Service\PricePoint;
use Psr\Log\LoggerInterface;

/**
 * Resilience decorator over a {@see PriceHistoryProvider} (in practice
 * {@see BinanceService}): retries a failed fetch a bounded number of times with
 * exponential backoff before giving up.
 *
 * The single external dependency (Binance) is called on a schedule, so a transient
 * network blip or 5xx would otherwise drop a pair's slot until the next 5-minute
 * tick. Retrying within the run recovers from those blips. Failures are surfaced
 * as the same {@see \RuntimeException} the port already declares, so the caller
 * ({@see \App\Application\ExchangeRate\Service\RateFetcher}) keeps isolating a permanently
 * failing pair exactly as before — this only adds attempts underneath.
 *
 * Kept separate from {@see BinanceService} (decorator, not inline) so the adapter
 * stays a thin SDK translator (SRP) and resilience is wired in via DI (OCP).
 */
final readonly class RetryingPriceHistoryProvider implements PriceHistoryProvider
{
    public function __construct(
        private PriceHistoryProvider $inner,
        private LoggerInterface $logger,
        /** Total attempts, including the first. Must be >= 1. */
        private int $maxAttempts = 3,
        /** Backoff base; the delay before attempt N is baseDelayMs * 2^(N-1). */
        private int $baseDelayMs = 200,
    ) {
    }

    /**
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when every attempt fails (the last failure is rethrown)
     */
    public function recentPricePoints(string $symbol, int $limit): array
    {
        return $this->withRetries($symbol, fn (): array => $this->inner->recentPricePoints($symbol, $limit));
    }

    /**
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when every attempt fails (the last failure is rethrown)
     */
    public function pricePointsBetween(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->withRetries($symbol, fn (): array => $this->inner->pricePointsBetween($symbol, $from, $to));
    }

    /**
     * @param callable(): list<PricePoint> $fetch
     *
     * @return list<PricePoint>
     *
     * @throws \RuntimeException when every attempt fails (the last failure is rethrown)
     */
    private function withRetries(string $symbol, callable $fetch): array
    {
        $attempt = 0;

        while (true) {
            ++$attempt;

            try {
                return $fetch();
            } catch (RateLimitException $e) {
                // Retrying now would add load exactly when Binance asks for less
                // (and escalate toward the 418 IP ban). The next scheduled run —
                // minutes out, far beyond any in-run backoff — is the retry.
                $this->logger->warning('Binance rate limited; deferring to the next scheduled run instead of retrying.', [
                    'symbol'    => $symbol,
                    'attempt'   => $attempt,
                    'exception' => $e,
                ]);

                throw $e;
            } catch (\RuntimeException $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }

                $delayMs = $this->baseDelayMs * (2 ** ($attempt - 1));
                $this->logger->warning('Binance price fetch failed; retrying.', [
                    'symbol'       => $symbol,
                    'attempt'      => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'delay_ms'     => $delayMs,
                    'exception'    => $e,
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }
    }
}
