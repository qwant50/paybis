<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\RateRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches a window of recent price points for every supported pair from Binance and
 * persists one rate sample per point, stamped with the point's grid-aligned open
 * time. Storing a window (not just the latest point) backfills any 5-minute slots
 * missed during downtime; already-stored slots are skipped idempotently.
 *
 * The window is sized per pair to its own trailing gap: in steady state each run
 * is a slot or two behind, so it re-affirms {@see self::MIN_CANDLES} recent slots
 * (≈1h of overlap); after downtime the pair's gap is wider, so the window grows to
 * cover it — up to {@see self::MAX_CANDLES} in one request. A gap larger than that
 * is logged and metered, and closed in the same run via the paging range fetch
 * ({@see PriceHistoryProvider::pricePointsBetween()}) — fetching only the
 * most-recent window would leave an interior hole behind it that no later run
 * (whose own gap is small again) would ever revisit.
 *
 * Failures are isolated on two levels so one bad pair or point never aborts the
 * rest: a pair whose fetch fails is logged and skipped, and a point that cannot be
 * stored is logged and skipped within its pair's batch (see {@see PricePointPersister}).
 */
final readonly class RateFetcher
{
    /**
     * The candle/slot length in seconds. Must match the candle interval fetched in
     * {@see \App\Infrastructure\Binance\BinanceService} and the scheduler cadence in
     * {@see \App\Infrastructure\Scheduler\RatesSchedule}.
     */
    private const int SLOT_INTERVAL_SECONDS = 5 * 60;

    /** Floor on the per-run window: re-affirm ≈1h of recent slots even with no gap. */
    private const int MIN_CANDLES = 12;

    /** Extra slots beyond the measured gap, to absorb boundary/rounding slack. */
    private const int OVERLAP_CANDLES = 2;

    /** Ceiling on a single run's window (Binance's own per-request maximum). */
    private const int MAX_CANDLES = 1000;

    public function __construct(
        private PriceHistoryProvider $binance,
        private RateRepository $repository,
        private PricePointPersister $persister,
        private LoggerInterface $logger,
        private Metrics $metrics,
        private ClockInterface $clock,
    ) {
    }

    public function fetchAll(): RateFetchReport
    {
        $runStart = microtime(true);
        $now = $this->clock->now();
        $stored = 0;
        $skipped = 0;
        $failed = 0;

        foreach (CurrencyPair::all() as $pair) {
            $fetchStart = microtime(true);
            try {
                $points = $this->fetchPoints($pair, $now);
            } catch (\Throwable $e) {
                ++$failed;
                $this->metrics->timing('binance.fetch.duration_ms', self::elapsedMs($fetchStart), ['pair' => $pair->value()]);
                $this->metrics->increment('binance.fetch', tags: ['pair' => $pair->value(), 'outcome' => 'failure']);
                $this->logger->error('Failed to fetch exchange rates.', [
                    'pair'      => $pair->value(),
                    'exception' => $e,
                ]);

                continue;
            }

            $this->metrics->timing('binance.fetch.duration_ms', self::elapsedMs($fetchStart), ['pair' => $pair->value()]);
            $this->metrics->increment('binance.fetch', tags: ['pair' => $pair->value(), 'outcome' => 'success']);

            $counts = $this->persister->persist($pair, $points);
            $stored += $counts['stored'];
            $skipped += $counts['skipped'];
            $failed += $counts['failed'];
        }

        $report = new RateFetchReport($stored, $skipped, $failed);

        $this->metrics->timing('rate_fetch.duration_ms', self::elapsedMs($runStart));
        $this->metrics->increment('rate_fetch.stored', $report->stored);
        $this->metrics->increment('rate_fetch.skipped', $report->skipped);
        $this->metrics->increment('rate_fetch.failed', $report->failed);

        $this->logger->info('Rate fetch run complete.', [
            'stored'  => $report->stored,
            'skipped' => $report->skipped,
            'failed'  => $report->failed,
        ]);

        return $report;
    }

    /**
     * Fetch the points that close a pair's trailing gap: enough recent candles to
     * cover the gap (plus overlap), or — when the gap outgrows one request's
     * window — the paging range fetch from the latest stored slot to now. With no
     * stored sample yet we bootstrap the widest single window available.
     *
     * @return list<PricePoint>
     */
    private function fetchPoints(CurrencyPair $pair, \DateTimeImmutable $now): array
    {
        $latest = $this->repository->latestRecordedAt($pair);
        if ($latest === null) {
            return $this->binance->recentPricePoints($pair->binanceSymbol(), self::MAX_CANDLES);
        }

        $gapSeconds = max(0, $now->getTimestamp() - $latest->getTimestamp());
        $slots = (int) ceil($gapSeconds / self::SLOT_INTERVAL_SECONDS) + self::OVERLAP_CANDLES;

        if ($slots > self::MAX_CANDLES) {
            $this->metrics->increment('rate_fetch.gap_exceeds_window', tags: ['pair' => $pair->value()]);
            $this->logger->warning('Pair gap exceeds one request\'s window; closing it via a paged range fetch.', [
                'pair'           => $pair->value(),
                'gap_seconds'    => $gapSeconds,
                'slots_needed'   => $slots,
                'window_candles' => self::MAX_CANDLES,
            ]);

            // Half-open [$from, $to): anchor at the latest stored slot (re-affirmed
            // idempotently) and reach one slot past "now" so the still-forming
            // candle is included even when "now" sits exactly on the grid.
            return $this->binance->pricePointsBetween(
                $pair->binanceSymbol(),
                $latest,
                $now->modify(sprintf('+%d seconds', self::SLOT_INTERVAL_SECONDS)),
            );
        }

        return $this->binance->recentPricePoints($pair->binanceSymbol(), max(self::MIN_CANDLES, $slots));
    }

    private static function elapsedMs(float $start): float
    {
        return (microtime(true) - $start) * 1000;
    }
}
