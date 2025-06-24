<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Outputs\Tui\TerminalUI;
use Whatsdiff\Services\DiffCalculator;
use Whatsdiff\Services\GitRepository;
use Whatsdiff\Services\ComposerAnalyzer;
use Whatsdiff\Services\NpmAnalyzer;
use Whatsdiff\Services\PackageInfoFetcher;

#[AsCommand(
    name: 'tui',
    description: 'Launch the Terminal User Interface to browse dependency changes',
    hidden: false,
)]
class TuiCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('This command launches an interactive TUI to browse changes in your project dependencies')
            ->addOption(
                'ignore-last',
                null,
                InputOption::VALUE_NONE,
                'Ignore last uncommitted changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreLast = (bool) $input->getOption('ignore-last');

        try {
            // Initialize services
            $git = new GitRepository();
            $packageInfoFetcher = new PackageInfoFetcher();
            $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
            $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);
            $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

            // Calculate diffs
            $result = $diffCalculator->calculateDiffs($ignoreLast);

            if (!$result->hasAnyChanges()) {
                $output->writeln('<info>No dependency changes detected.</info>');
                return Command::SUCCESS;
            }

            // Convert to flat array for TUI
            $packageDiffs = $this->convertToTuiFormat($result);

            // Launch TUI
            $tui = new TerminalUI($packageDiffs);
            $tui->prompt();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function convertToTuiFormat(\Whatsdiff\Data\DiffResult $result): array
    {
        $packages = [];

        foreach ($result->diffs as $diff) {
            foreach ($diff->changes as $change) {
                $packages[] = [
                    'name' => $change->name,
                    'type' => $change->type,
                    'from' => $change->from,
                    'to' => $change->to,
                    'status' => $change->status->value,
                    'releases' => $change->releaseCount,
                    'filename' => $diff->filename,
                ];
            }
        }

        return $packages;
    }
}
