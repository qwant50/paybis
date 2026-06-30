<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Domain\ExchangeRate\RateRepository;
use Psr\Log\LoggerInterface;

/**
 * Persists a pair's price points as rate samples, stamped with each point's
 * grid-aligned open time. Shared by {@see RateFetcher} (scheduled window) and
 * {@see RateBackfiller} (manual range) so the parse-store-count behaviour lives in
 * one place.
 *
 * A single bad point (e.g. one that loses precision, or a non-positive price) is
 * logged and skipped so it never aborts the rest of the pair's batch; storing is
 * idempotent, so an already-present slot is counted as skipped rather than
 * re-inserted.
 *
 * A plausible-but-extreme jump between adjacent points is *stored anyway* — open
 * prices are exchange truth and flash moves are real — but metered and logged
 * ({@see self::ANOMALY_JUMP_RATIO}) so an operator can check whether it was a
 * genuine move or corrupt upstream data.
 */
final readonly class PricePointPersister
{
    /**
     * Relative change between adjacent points above which a move is flagged as
     * anomalous (0.2 = 20% per 5-minute slot — far beyond normal volatility for
     * the supported pairs). A heuristic for surfacing, never a gate.
     */
    private const float ANOMALY_JUMP_RATIO = 0.2;

    public function __construct(
        private RateRepository $repository,
        private LoggerInterface $logger,
        private Metrics $metrics,
    ) {
    }

    /**
     * @param list<PricePoint> $points
     *
     * @return array{stored: int, skipped: int, failed: int}
     */
    public function persist(CurrencyPair $pair, array $points): array
    {
        $stored = 0;
        $skipped = 0;
        $failed = 0;
        $previous = null;

        foreach ($points as $point) {
            try {
                $rate = Rate::fromString($point->price);
                $this->flagAnomalousJump($pair, $previous, $rate, $point);
                $previous = $rate;

                $inserted = $this->repository->save(new ExchangeRate($pair, $rate, $point->time));

                if ($inserted) {
                    ++$stored;
                    $this->logger->info('Stored exchange rate.', [
                        'pair'        => $pair->value(),
                        'price'       => $rate->asString(),
                        'recorded_at' => $point->time->format(\DateTimeInterface::ATOM),
                    ]);
                } else {
                    ++$skipped;
                }
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to store exchange rate.', [
                    'pair'        => $pair->value(),
                    'recorded_at' => $point->time->format(\DateTimeInterface::ATOM),
                    'exception'   => $e,
                ]);
            }
        }


        return ['stored' => $stored, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Meter + warn when the move from the previous point is implausibly large.
     * Float arithmetic is fine here: this is a surfacing heuristic, not a gate,
     * and the stored value stays exact.
     */
    private function flagAnomalousJump(CurrencyPair $pair, ?Rate $previous, Rate $current, PricePoint $point): void
    {
        if ($previous === null) {
            return;
        }

        $previousPrice = $previous->toFloat();
        $ratio = abs($current->toFloat() - $previousPrice) / $previousPrice;
        if ($ratio <= self::ANOMALY_JUMP_RATIO) {
            return;
        }

        $this->metrics->increment('rate_anomaly.price_jump', tags: ['pair' => $pair->value()]);
        $this->logger->warning('Anomalous price jump between adjacent points; stored anyway.', [
            'pair'           => $pair->value(),
            'previous_price' => $previous->asString(),
            'price'          => $current->asString(),
            'change_ratio'   => round($ratio, 4),
            'recorded_at'    => $point->time->format(\DateTimeInterface::ATOM),
        ]);
    }
}
