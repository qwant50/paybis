<?php

declare(strict_types=1);

namespace App\Application\Service;

use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\ExchangeRate;
use App\Domain\ExchangeRate\Rate;
use App\Domain\ExchangeRate\RateRepository;
use Psr\Log\LoggerInterface;

/**
 * Fetches the latest closed 5-minute candle for every supported pair from Binance
 * and persists one rate sample per pair, stamped with the candle's grid-aligned
 * open time. A failure for one pair is logged and isolated so it never aborts the
 * others.
 */
final readonly class RateFetcher
{
    public function __construct(
        private ClosedCandleProvider $binance,
        private RateRepository $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function fetchAll(): RateFetchReport
    {
        $fetched = 0;
        $failed = 0;

        foreach (CurrencyPair::all() as $pair) {
            try {
                $candle = $this->binance->latestClosedCandle($pair->binanceSymbol());
                $rate = Rate::fromString($candle->closePrice);

                $this->repository->save(new ExchangeRate($pair, $rate, $candle->openTime));

                ++$fetched;
                $this->logger->info('Stored exchange rate.', [
                    'pair'        => $pair->value(),
                    'price'       => $rate->asString(),
                    'recorded_at' => $candle->openTime->format(\DateTimeInterface::ATOM),
                ]);
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to fetch exchange rate.', [
                    'pair'      => $pair->value(),
                    'exception' => $e,
                ]);
            }
        }

        $report = new RateFetchReport($fetched, $failed);
        $this->logger->info('Rate fetch run complete.', [
            'fetched' => $report->fetched,
            'failed'  => $report->failed,
        ]);

        return $report;
    }
}
