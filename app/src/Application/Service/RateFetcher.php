<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Domain\ExchangeRate\RateRepository;
use Psr\Log\LoggerInterface;

/**
 * Fetches a window of recent price points for every supported pair from Binance and
 * persists one rate sample per point, stamped with the point's grid-aligned open
 * time. Storing a window (not just the latest point) backfills any 5-minute slots
 * missed during downtime; already-stored slots are skipped idempotently.
 *
 * Failures are isolated on two levels so one bad pair or point never aborts the
 * rest: a pair whose fetch fails is logged and skipped, and a point that cannot be
 * parsed or stored is logged and skipped within its pair's batch.
 */
final readonly class RateFetcher
{
    public function __construct(
        private PriceHistoryProvider $binance,
        private RateRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchAll(): RateFetchReport
    {
        $stored = 0;
        $skipped = 0;
        $failed = 0;

        foreach (CurrencyPair::all() as $pair) {
            try {
                $points = $this->binance->recentPricePoints($pair->binanceSymbol());
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to fetch exchange rates.', [
                    'pair'      => $pair->value(),
                    'exception' => $e,
                ]);

                continue;
            }

            foreach ($points as $point) {
                try {
                    $rate = Rate::fromString($point->price);
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
        }

        $report = new RateFetchReport($stored, $skipped, $failed);
        $this->logger->info('Rate fetch run complete.', [
            'stored'  => $report->stored,
            'skipped' => $report->skipped,
            'failed'  => $report->failed,
        ]);

        return $report;
    }
}
