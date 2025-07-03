<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Container\Container;
use Whatsdiff\Outputs\Tui\TerminalUI;
use Whatsdiff\Services\CacheService;
use Whatsdiff\Services\DiffCalculator;

#[AsCommand(
    name: 'tui',
    description: 'Launch the Terminal User Interface to browse dependency changes',
    hidden: false,
)]
class TuiCommand extends Command
{
    private Container $container;

    public function __construct(Container $container)
    {
        parent::__construct();
        $this->container = $container;
    }
    protected function configure(): void
    {
        $this
            ->setHelp('This command launches an interactive TUI to browse changes in your project dependencies')
            ->addOption(
                'ignore-last',
                null,
                InputOption::VALUE_NONE,
                'Ignore last uncommitted changes'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching for this request'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreLast = (bool) $input->getOption('ignore-last');
        $noCache = (bool) $input->getOption('no-cache');

        try {
            // Get services from container
            $cacheService = $this->container->get(CacheService::class);
            $diffCalculator = $this->container->get(DiffCalculator::class);

            // Disable cache if requested
            if ($noCache) {
                $cacheService->disableCache();
            }

            if ($ignoreLast) {
                $diffCalculator->ignoreLastCommit();
            }

            $result = $diffCalculator->run();

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
                    'type' => $change->type->value,
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
