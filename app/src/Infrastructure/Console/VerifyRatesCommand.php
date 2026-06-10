<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\ExchangeRate\Service\RateFeedIntegrityChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the trailing 24h of the stored feed has no missing 5-minute slots and
 * repairs any holes it finds. The hourly scheduled run does the same; this command
 * exists for on-demand verification (e.g. after an incident or a manual backfill).
 *
 * Entry point only — detection and repair live in {@see RateFeedIntegrityChecker}.
 */
#[AsCommand(
    name: 'app:rates:verify',
    description: 'Verify the trailing 24h of stored rates is gap-free; repair any holes.',
)]
final class VerifyRatesCommand extends Command
{
    public function __construct(private readonly RateFeedIntegrityChecker $checker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->checker->check();

        $summary = sprintf(
            'Checked %d of %d pairs: %d missing, %d repaired, %d unrepaired, %d pair failures.',
            $report->checkedPairs,
            $report->checkedPairs + $report->failedPairs,
            $report->missingSlots,
            $report->repairedSlots,
            $report->unrepairedSlots,
            $report->failedPairs,
        );

        if ($report->hasFailures()) {
            $io->error($summary);

            return Command::FAILURE;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }
}
