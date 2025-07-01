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

use function Laravel\Prompts\clear;
use function Laravel\Prompts\progress;

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
            ->setHelp('This command analyzes changes in your project dependencies (composer.lock and package-lock.json). You can compare dependency changes between any two commits using --from and --to options.')
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
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Commit hash, branch, or tag to compare from (older version)'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Commit hash, branch, or tag to compare to (newer version, defaults to HEAD)'
            )
            ->addOption(
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar output'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreLast = (bool) $input->getOption('ignore-last');
        $format = $input->getOption('format');
        $noCache = (bool) $input->getOption('no-cache');
        $fromCommit = $input->getOption('from');
        $toCommit = $input->getOption('to');
        $noAnsi = ! $output->isDecorated();

        // Validate options
        if (($fromCommit || $toCommit) && $ignoreLast) {
            $output->writeln('<error>Cannot use --ignore-last with --from or --to options</error>');

            return Command::FAILURE;
        }

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

            // Calculate diffs using fluent interface
            $calculator = $diffCalculator;

            if ($ignoreLast) {
                $calculator = $calculator->ignoreLastCommit();
            }

            if ($fromCommit !== null) {
                $calculator = $calculator->fromCommit($fromCommit);
            }

            if ($toCommit !== null) {
                $calculator = $calculator->toCommit($toCommit);
            }

            if ($this->shouldShowProgress($format, $noAnsi, $input)) {
                [$total, $generator] = $calculator->run(withProgress: true);

                // Use Laravel Prompts for progress bar
                if ($total) {
                    $output->writeln('');
                    $this->showProgressBar($total, $generator);
                }

                $result = $calculator->getResult();

            } else {
                $result = $calculator->run();
            }

            // Get appropriate formatter
            $formatter = $this->getFormatter($format, $noAnsi);

            // Output results
            $formatter->format($result, $output);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $output->writeln('<error>Error: '.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
    }

    private function getFormatter(string $format, bool $noAnsi): OutputFormatterInterface
    {
        return match ($format) {
            'json' => new JsonOutput(),
            'markdown' => new MarkdownOutput(),
            default => new TextOutput(! $noAnsi),
        };
    }

    private function shouldShowProgress($format, bool $noAnsi, InputInterface $input): bool
    {
        // // TODO: We'll put a config for that later
        // return false;

        return $format == 'text' && $noAnsi === false
            && $input->isInteractive()
            && ! $input->hasParameterOption('--no-progress');
    }

    /**
     * @param  mixed  $total
     * @param  mixed  $generator
     * @return void
     */
    public function showProgressBar(mixed $total, mixed $generator): void
    {
        $progress = progress(label: 'Analysing changes..', steps: $total);

        $progress->start();

        foreach ($generator as $package) {
            $progress->advance();
        }

        $progress->finish();
        // clear();
    }
}
