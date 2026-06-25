<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Drives the periodic rate work: a {@see FetchRatesMessage} every 5 minutes and a
 * {@see CheckFeedIntegrityMessage} every hour.
 *
 * The schedule is stateful (it remembers the last run) so missed ticks while
 * the worker is down are caught up on restart.
 */
#[AsSchedule('rates')]
final class RatesSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        // The cadence must match the candle interval fetched in
        // App\Infrastructure\Binance\BinanceService (Interval::INTERVAL_5M): each
        // tick captures the previous closed 5-minute candle.
        return new Schedule()
            ->add(RecurringMessage::every('5 minutes', new FetchRatesMessage()))
            ->add(RecurringMessage::every('1 hour', new CheckFeedIntegrityMessage()))
            ->stateful($this->cache);
    }
}
