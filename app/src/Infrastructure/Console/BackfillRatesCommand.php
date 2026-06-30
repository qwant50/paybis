<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\ExchangeRate\Service\RateBackfiller;
use App\Domain\ExchangeRate\CurrencyPair;
use App\Domain\ExchangeRate\Day;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs the stored feed over a UTC date range by re-fetching every 5-minute slot
 * in it. Intended for manual recovery of gaps wider than the scheduled run's
 * automatic backfill window (e.g. after extended downtime) or specific interior
 * holes. Idempotent: re-running a range only fills genuine holes.
 *
 *   bin/console app:rates:backfill --from=2026-06-08 --to=2026-06-09
 *   bin/console app:rates:backfill --from=2026-06-08 --pair=EUR/BTC
 *
 * Entry point only — parsing and orchestration live in the domain VOs and
 * {@see RateBackfiller}.
 */
#[AsCommand(
    name: 'app:rates:backfill',
    description: 'Backfill stored EUR rates for a UTC date range (inclusive of --to).',
)]
final class BackfillRatesCommand extends Command
{
    public function __construct(private readonly RateBackfiller $backfiller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start day, UTC (YYYY-MM-DD), inclusive.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End day, UTC (YYYY-MM-DD), inclusive. Defaults to today.')
            ->addOption('pair', null, InputOption::VALUE_REQUIRED, 'Restrict to one pair (e.g. EUR/BTC). Defaults to all supported pairs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromOption = $input->getOption('from');
        if (!is_string($fromOption) || $fromOption === '') {
            $io->error('The --from option is required (UTC YYYY-MM-DD).');

            return Command::INVALID;
        }
        $toOption = $input->getOption('to');
        $pairOption = $input->getOption('pair');

        try {
            $from = Day::fromString($fromOption)->toDateTime();

            // Half-open [from 00:00, to+1 day 00:00) so the --to day is included in full;
            // with no --to, cover everything up to the current instant.
            $to = is_string($toOption) && $toOption !== ''
                ? Day::fromString($toOption)->toDateTime()->modify('+1 day')
                : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            $pair = is_string($pairOption) && $pairOption !== '' ? CurrencyPair::fromString($pairOption) : null;
        } catch (\InvalidArgumentException $e) {
            // Domain input errors (InvalidDateException / InvalidPairException) carry
            // client-safe messages.
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        if ($from >= $to) {
            $io->error('The --from day must be before --to.');

            return Command::INVALID;
        }

        $report = $this->backfiller->backfill($from, $to, $pair);

        $io->success(sprintf(
            'Backfilled %s..%s%s: stored %d, skipped %d, failed %d.',
            $from->format('Y-m-d H:i'),
            $to->format('Y-m-d H:i'),
            $pair !== null ? ' for ' . $pair->value() : '',
            $report->stored,
            $report->skipped,
            $report->failed,
        ));

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
