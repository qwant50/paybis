<?php

declare(strict_types=1);

namespace App\Application\ExchangeRate\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\RateRepository;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Detects and repairs missing 5-minute slots ("holes") in each pair's stored feed
 * over the trailing 24 hours.
 *
 * Detection anchors at *stored* slots: the expected grid runs from the first to
 * the last sample found inside the window, so only interior contiguity is checked
 * — the sliding window edge, short pair histories, and trailing freshness (the
 * health check's job) never raise false alarms.
 *
 * Repair reuses {@see RateBackfiller} over the hole-spanning range; persistence is
 * idempotent, so already-present slots are skipped and only genuine holes are
 * filled. Slots still missing after the repair (e.g. Binance had no candle while
 * it was down) are metered and logged rather than retried in a loop — they
 * re-flag on later runs until they age out of the window.
 *
 * Failures are isolated per pair: one pair's error is logged and counted, never
 * aborting the others — the same policy as {@see RateFetcher}.
 */
final readonly class RateFeedIntegrityChecker
{
    /**
     * The slot length in seconds. Must match the candle interval fetched in
     * {@see \App\Infrastructure\Binance\BinanceService} and the scheduler cadence
     * in {@see \App\Infrastructure\Scheduler\RatesSchedule}.
     */
    private const int SLOT_INTERVAL_SECONDS = 5 * 60;

    /** How far back each run scans for holes. */
    private const int LOOKBACK_SECONDS = 24 * 60 * 60;

    public function __construct(
        private RateRepository $repository,
        private RateBackfiller $backfiller,
        private LoggerInterface $logger,
        private Metrics $metrics,
        private ClockInterface $clock,
    ) {
    }

    public function check(): FeedIntegrityReport
    {
        $runStart = microtime(true);
        $now = $this->clock->now();
        $from = $now->modify(sprintf('-%d seconds', self::LOOKBACK_SECONDS));

        $checkedPairs = 0;
        $failedPairs = 0;
        $missingSlots = 0;
        $repairedSlots = 0;
        $unrepairedSlots = 0;

        foreach (CurrencyPair::all() as $pair) {
            try {
                [$missing, $unrepaired] = $this->checkPair($pair, $from, $now);
            } catch (\Throwable $e) {
                ++$failedPairs;
                $this->logger->error('Feed integrity check failed for pair.', [
                    'pair'      => $pair->value(),
                    'exception' => $e,
                ]);

                continue;
            }

            ++$checkedPairs;
            $missingSlots += count($missing);
            $unrepairedSlots += count($unrepaired);
            $repairedSlots += count($missing) - count($unrepaired);
        }

        $report = new FeedIntegrityReport($checkedPairs, $failedPairs, $missingSlots, $repairedSlots, $unrepairedSlots);

        $this->metrics->timing('rate_integrity.duration_ms', (microtime(true) - $runStart) * 1000);
        $this->logger->info('Feed integrity check complete.', [
            'checked_pairs'    => $report->checkedPairs,
            'failed_pairs'     => $report->failedPairs,
            'missing_slots'    => $report->missingSlots,
            'repaired_slots'   => $report->repairedSlots,
            'unrepaired_slots' => $report->unrepairedSlots,
        ]);

        return $report;
    }

    /**
     * @return array{list<\DateTimeImmutable>, list<\DateTimeImmutable>} the pair's
     *                                                                   [missing, still-missing-after-repair] slots
     */
    private function checkPair(CurrencyPair $pair, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $stored = $this->storedSlots($pair, $from, $to);
        if ($stored === []) {
            // Bootstrap pending or feed dead; staleness is the health check's job.
            $this->logger->warning('No samples in the integrity window; skipping pair.', [
                'pair' => $pair->value(),
            ]);

            return [[], []];
        }

        $missing = self::missingSlots($stored);
        if ($missing === []) {
            return [[], []];
        }

        $this->metrics->increment('rate_integrity.missing_slots', count($missing), ['pair' => $pair->value()]);
        $this->logger->warning('Feed has missing slots; repairing.', [
            'pair'  => $pair->value(),
            'count' => count($missing),
            'slots' => self::formatSlots($missing),
        ]);

        $repairFrom = $missing[0];
        $repairTo = $missing[count($missing) - 1]->modify(sprintf('+%d seconds', self::SLOT_INTERVAL_SECONDS));

        // The backfiller's own report is not authoritative here (it isolates and
        // counts its failures internally) — re-reading the stored slots is.
        $this->backfiller->backfill($repairFrom, $repairTo, $pair);

        $present = array_flip(array_map(
            static fn (\DateTimeImmutable $slot): int => $slot->getTimestamp(),
            $this->storedSlots($pair, $repairFrom, $repairTo),
        ));
        $unrepaired = array_values(array_filter(
            $missing,
            static fn (\DateTimeImmutable $slot): bool => !isset($present[$slot->getTimestamp()]),
        ));

        if ($unrepaired !== []) {
            $this->metrics->increment('rate_integrity.unrepaired_slots', count($unrepaired), ['pair' => $pair->value()]);
            $this->logger->error('Feed slots could not be repaired; the upstream may have no candles for them.', [
                'pair'  => $pair->value(),
                'count' => count($unrepaired),
                'slots' => self::formatSlots($unrepaired),
            ]);
        }

        return [$missing, $unrepaired];
    }

    /**
     * @return list<\DateTimeImmutable> the pair's stored slot times within [$from, $to), ascending
     */
    private function storedSlots(CurrencyPair $pair, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return array_map(
            static fn (ExchangeRate $rate): \DateTimeImmutable => $rate->recordedAt,
            $this->repository->findBetween($pair, $from, $to),
        );
    }

    /**
     * Every 5-minute slot absent between the first and last stored slot — interior
     * contiguity only, so the window edge and short histories raise no false alarms.
     *
     * The anchor is snapped *up* to the grid: an off-grid row (manual SQL, a
     * future bug) anchoring the walk would shift every expected slot off the real
     * grid, flagging the entire window as missing and firing a mass false repair
     * on every run. For on-grid data the snap is a no-op.
     *
     * @param non-empty-list<\DateTimeImmutable> $stored ascending
     *
     * @return list<\DateTimeImmutable>
     */
    private static function missingSlots(array $stored): array
    {
        $present = [];
        foreach ($stored as $slot) {
            $present[$slot->getTimestamp()] = true;
        }

        $missing = [];
        $first = $stored[0]->getTimestamp();
        $start = intdiv($first + self::SLOT_INTERVAL_SECONDS - 1, self::SLOT_INTERVAL_SECONDS) * self::SLOT_INTERVAL_SECONDS;
        $last = $stored[count($stored) - 1]->getTimestamp();
        for ($ts = $start; $ts <= $last; $ts += self::SLOT_INTERVAL_SECONDS) {
            if (!isset($present[$ts])) {
                // The `@` epoch format is always UTC.
                $missing[] = new \DateTimeImmutable('@' . $ts)->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        return $missing;
    }

    /**
     * @param list<\DateTimeImmutable> $slots
     *
     * @return list<string>
     */
    private static function formatSlots(array $slots): array
    {
        return array_map(
            static fn (\DateTimeImmutable $slot): string => $slot->format(\DateTimeInterface::ATOM),
            $slots,
        );
    }
}
