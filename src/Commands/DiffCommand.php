<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Outputs\JsonOutput;
use Whatsdiff\Outputs\MarkdownOutput;
use Whatsdiff\Outputs\OutputFormatterInterface;
use Whatsdiff\Outputs\TextOutput;
use Whatsdiff\Services\CacheService;
use Whatsdiff\Services\ConfigService;
use Whatsdiff\Services\DiffCalculator;
use Whatsdiff\Services\GitRepository;
use Whatsdiff\Services\HttpService;
use Whatsdiff\Services\PackageInfoFetcher;

#[AsCommand(
    name: 'diff',
    description: 'See what\'s changed in your project\'s dependencies',
    hidden: false,
)]
class DiffCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setHelp('This command analyzes changes in your project dependencies (composer.lock and package-lock.json)')
            ->addOption(
                'ignore-last',
                null,
                InputOption::VALUE_NONE,
                'Ignore last uncommitted changes'
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (text, json, markdown)',
                'text'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching for this request'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreLast = (bool) $input->getOption('ignore-last');
        $format = $input->getOption('format');
        $noCache = (bool) $input->getOption('no-cache');
        $noAnsi = !$output->isDecorated();

        try {
            // Initialize services
            $configService = new ConfigService();
            $cacheService = new CacheService($configService);

            // Disable cache if requested
            if ($noCache) {
                $cacheService->disableCache();
            }

            $httpService = new HttpService($cacheService);
            $git = new GitRepository();
            $packageInfoFetcher = new PackageInfoFetcher($httpService);
            $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
            $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);
            $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

            // Calculate diffs
            $result = $diffCalculator->calculateDiffs($ignoreLast);

            // Get appropriate formatter
            $formatter = $this->getFormatter($format, $noAnsi);

            // Output results
            $formatter->format($result, $output);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function getFormatter(string $format, bool $noAnsi): OutputFormatterInterface
    {
        return match ($format) {
            'json' => new JsonOutput(),
            'markdown' => new MarkdownOutput(),
            default => new TextOutput(!$noAnsi),
        };
    }
}
