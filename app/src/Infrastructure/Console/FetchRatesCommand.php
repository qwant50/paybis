<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Service\RateFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches the current rate for every supported pair once. Intended for manual
 * runs and smoke tests; the periodic run is driven by Symfony Scheduler.
 */
#[AsCommand(
    name: 'app:rates:fetch',
    description: 'Fetch the latest EUR rates for all supported pairs and store them.',
)]
final class FetchRatesCommand extends Command
{
    public function __construct(private readonly RateFetcher $rateFetcher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $report = $this->rateFetcher->fetchAll();

        $io->success(sprintf(
            'Stored %d new, skipped %d, failed %d.',
            $report->stored,
            $report->skipped,
            $report->failed,
        ));

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }
}
