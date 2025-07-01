<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Data\ChangeStatus;
use Whatsdiff\Enums\CheckType;
use Whatsdiff\Services\CacheService;
use Whatsdiff\Services\ConfigService;
use Whatsdiff\Services\DiffCalculator;
use Whatsdiff\Services\GitRepository;
use Whatsdiff\Services\HttpService;
use Whatsdiff\Services\PackageInfoFetcher;

#[AsCommand(
    name: 'check',
    description: 'Check if a specific package has changed',
    hidden: false,
)]
class CheckCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('Check if a specific package has changed in your project dependencies')
            ->addArgument(
                'package',
                InputArgument::REQUIRED,
                'Package name to check (e.g., livewire/livewire)'
            )
            ->addOption(
                'has-any-change',
                null,
                InputOption::VALUE_NONE,
                'Check if package has any change (default)'
            )
            ->addOption(
                'is-updated',
                null,
                InputOption::VALUE_NONE,
                'Check if package was updated'
            )
            ->addOption(
                'is-downgraded',
                null,
                InputOption::VALUE_NONE,
                'Check if package was downgraded'
            )
            ->addOption(
                'is-removed',
                null,
                InputOption::VALUE_NONE,
                'Check if package was removed'
            )
            ->addOption(
                'is-added',
                null,
                InputOption::VALUE_NONE,
                'Check if package was added'
            )
            ->addOption(
                'quiet',
                'q',
                InputOption::VALUE_NONE,
                'Suppress all output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageName = $input->getArgument('package');
        $quiet = $input->getOption('quiet');

        // Determine which check to perform
        $checkType = $this->determineCheckType($input);

        // Initialize services
        try {
            $configService = new ConfigService();
            $cacheService = new CacheService($configService);
            $httpService = new HttpService($cacheService);

            $gitRepository = new GitRepository();
            $packageInfoFetcher = new PackageInfoFetcher($httpService);
            $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
            $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);

            $diffCalculator = new DiffCalculator($gitRepository, $composerAnalyzer, $npmAnalyzer);

            // Calculate diffs - skip release count for performance
            $diffResult = $diffCalculator->calculateDiffs(ignoreLast: false, skipReleaseCount: true);

            // Find the specific package in the results
            $packageChange = null;
            foreach ($diffResult->diffs as $dependencyDiff) {
                foreach ($dependencyDiff->changes as $change) {
                    if ($change->name === $packageName) {
                        $packageChange = $change;
                        break 2;
                    }
                }
            }

            // Determine result based on check type
            $result = $this->evaluatePackageChange($packageChange, $checkType);

            if (! $quiet) {
                $output->writeln($result ? 'true' : 'false');
            }

            return $result ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            if (! $quiet) {
                $output->writeln('<error>Error: '.$e->getMessage().'</error>');
            }

            return Command::INVALID; // Error exit code
        }
    }

    private function determineCheckType(InputInterface $input): CheckType
    {
        $options = [
            'is-updated'    => CheckType::Updated,
            'is-downgraded' => CheckType::Downgraded,
            'is-removed'    => CheckType::Removed,
            'is-added'      => CheckType::Added,
        ];

        foreach ($options as $option => $type) {
            if ($input->getOption($option)) {
                return $type;
            }
        }

        // Default to checking any change
        return CheckType::Any;
    }

    private function evaluatePackageChange($packageChange, CheckType $checkType): bool
    {
        if ($packageChange === null) {
            // Package not found in changes means no change
            return false;
        }

        return match ($checkType) {
            CheckType::Any => true, // Any change found
            CheckType::Updated => $packageChange->status === ChangeStatus::Updated,
            CheckType::Downgraded => $packageChange->status === ChangeStatus::Downgraded,
            CheckType::Removed => $packageChange->status === ChangeStatus::Removed,
            CheckType::Added => $packageChange->status === ChangeStatus::Added,
        };
    }
}
