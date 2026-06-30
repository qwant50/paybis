<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use Psr\Log\LoggerInterface;

/**
 * Repairs the stored feed over an explicit UTC window [$from, $to): fetches every
 * grid-aligned price point in the range for the target pair(s) and persists it.
 *
 * Unlike {@see RateFetcher} (anchored to "now" and the trailing gap), this is
 * anchored to a caller-given range, so it fills arbitrary historical gaps and
 * interior holes — e.g. after downtime longer than one scheduled run can cover.
 * Storing is idempotent, so re-running a range is safe: already-present slots are
 * skipped, and only genuine holes are inserted.
 *
 * Failures are isolated per pair (one pair's failure never aborts the others) and
 * per point (handled in {@see PricePointPersister}), exactly as the scheduled run.
 */
final readonly class RateBackfiller
{
    public function __construct(
        private PriceHistoryProvider $binance,
        private PricePointPersister $persister,
        private LoggerInterface $logger,
        private Metrics $metrics,
    ) {
    }

    /**
     * @param CurrencyPair|null $only restrict to a single pair; null backfills all supported pairs
     */
    public function backfill(\DateTimeImmutable $from, \DateTimeImmutable $to, ?CurrencyPair $only = null): RateFetchReport
    {
        $runStart = microtime(true);
        $pairs = $only !== null ? [$only] : CurrencyPair::all();
        $stored = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($pairs as $pair) {
            try {
                $points = $this->binance->pricePointsBetween($pair->binanceSymbol(), $from, $to);
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to fetch exchange rates for backfill.', [
                    'pair'      => $pair->value(),
                    'from'      => $from->format(\DateTimeInterface::ATOM),
                    'to'        => $to->format(\DateTimeInterface::ATOM),
                    'exception' => $e,
                ]);

                continue;
            }

            $counts = $this->persister->persist($pair, $points);
            $stored += $counts['stored'];
            $skipped += $counts['skipped'];
            $failed += $counts['failed'];
        }

        $report = new RateFetchReport($stored, $skipped, $failed);

        $this->metrics->timing('rate_backfill.duration_ms', (microtime(true) - $runStart) * 1000);
        $this->metrics->increment('rate_backfill.stored', $report->stored);
        $this->metrics->increment('rate_backfill.skipped', $report->skipped);
        $this->metrics->increment('rate_backfill.failed', $report->failed);

        $this->logger->info('Rate backfill run complete.', [
            'from'    => $from->format(\DateTimeInterface::ATOM),
            'to'      => $to->format(\DateTimeInterface::ATOM),
            'stored'  => $report->stored,
            'skipped' => $report->skipped,
            'failed'  => $report->failed,
        ]);

        return $report;
    }
}
