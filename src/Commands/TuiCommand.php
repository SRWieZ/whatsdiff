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

            // Get package diffs
            $packageDiffs = $this->getPackageDiffs($diffCalculator, $ignoreLast);

            if (empty($packageDiffs)) {
                $output->writeln('<info>No dependency changes detected.</info>');
                return Command::SUCCESS;
            }

            // Launch TUI
            $tui = new TerminalUI($packageDiffs);
            $tui->prompt();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getPackageDiffs(DiffCalculator $diffCalculator, bool $ignoreLast): array
    {
        // This method would need to be extracted from DiffCalculator
        // to return the package diffs data structure instead of printing to output
        // For now, returning a mock structure
        return [
            [
                'name' => 'example/package',
                'type' => 'composer',
                'from' => '1.0.0',
                'to' => '1.1.0',
                'status' => 'updated',
                'releases' => 3,
            ],
            [
                'name' => 'another/package',
                'type' => 'composer',
                'from' => null,
                'to' => '2.0.0',
                'status' => 'added',
                'releases' => null,
            ],
        ];
    }
}
