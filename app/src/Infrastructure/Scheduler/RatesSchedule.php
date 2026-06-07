<?php

declare(strict_types=1);

namespace App\Infrastructure\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Drives the periodic rate fetch: a {@see FetchRatesMessage} every 5 minutes.
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
        return new Schedule()
            ->add(RecurringMessage::every('5 minutes', new FetchRatesMessage()))
            ->stateful($this->cache);
    }
}
