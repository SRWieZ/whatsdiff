<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Container\Container;
use Whatsdiff\Outputs\OutputFormatterInterface;
use Whatsdiff\Services\CacheService;
use Whatsdiff\Services\DiffCalculator;

use function Laravel\Prompts\clear;
use function Laravel\Prompts\progress;

#[AsCommand(
    name: 'analyse',
    description: 'See what\'s changed in your project\'s dependencies',
    hidden: false,
)]
class AnalyseCommand extends Command
{
    private Container $container;

    public function __construct(Container $container)
    {
        parent::__construct();
        $this->container = $container;
    }
    /**
     * Get shared options that can be used by multiple commands
     */
    public static function getSharedOptions(): array
    {
        return [
            [
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format (text, json, markdown)',
                'text'
            ],
            [
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable caching for this request'
            ],
            [
                'include',
                null,
                InputOption::VALUE_REQUIRED,
                'Include only specific package manager types (comma-separated: composer,npmjs)'
            ],
            [
                'exclude',
                null,
                InputOption::VALUE_REQUIRED,
                'Exclude specific package manager types (comma-separated: composer,npmjs)'
            ],
            [
                'no-progress',
                null,
                InputOption::VALUE_NONE,
                'Disable progress bar output'
            ],
        ];
    }

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
            );

        // Add shared options
        foreach (self::getSharedOptions() as $option) {
            $this->addOption(...$option);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ignoreLast = (bool) $input->getOption('ignore-last');
        $format = $input->getOption('format');
        $noCache = (bool) $input->getOption('no-cache');
        $fromCommit = $input->getOption('from');
        $toCommit = $input->getOption('to');
        $includeTypes = $input->getOption('include');
        $excludeTypes = $input->getOption('exclude');
        $noAnsi = ! $output->isDecorated();

        // Validate options
        if (($fromCommit || $toCommit) && $ignoreLast) {
            $output->writeln('<error>Cannot use --ignore-last with --from or --to options</error>');

            return Command::FAILURE;
        }

        if ($includeTypes && $excludeTypes) {
            $output->writeln('<error>Cannot use both --include and --exclude options</error>');

            return Command::FAILURE;
        }

        try {
            // Get services from container
            $cacheService = $this->container->get(CacheService::class);
            /** @var DiffCalculator $diffCalculator */
            $diffCalculator = $this->container->get(DiffCalculator::class);

            // Disable cache if requested
            if ($noCache) {
                $cacheService->disableCache();
            }

            // Parse dependency types from include/exclude options
            $dependencyTypes = $this->parseDependencyTypes($includeTypes, $excludeTypes, $output);
            if ($dependencyTypes === null) {
                return Command::FAILURE;
            }

            // Configure dependency types if specified
            foreach ($dependencyTypes as $type) {
                $diffCalculator->for($type);
            }

            if ($ignoreLast) {
                $diffCalculator->ignoreLastCommit();
            }

            if ($fromCommit !== null) {
                $diffCalculator->fromCommit($fromCommit);
            }

            if ($toCommit !== null) {
                $diffCalculator->toCommit($toCommit);
            }

            if ($this->shouldShowProgress($format, $noAnsi, $input)) {
                [$total, $generator] = $diffCalculator->run(withProgress: true);

                // Use Laravel Prompts for progress bar
                if ($total) {
                    $output->writeln('');
                    $this->showProgressBar($total, $generator);
                }

                $result = $diffCalculator->getResult();

            } else {
                $result = $diffCalculator->run();
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
        /** @var callable $formatterFactory */
        $formatterFactory = $this->container->get('formatter.factory');
        return $formatterFactory($format, ! $noAnsi);
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

    /**
     * Parse dependency types from include/exclude options
     *
     * @return array<PackageManagerType>|null Returns null on error
     */
    private function parseDependencyTypes(?string $includeTypes, ?string $excludeTypes, OutputInterface $output): ?array
    {
        $allTypes = PackageManagerType::cases();

        // If neither include nor exclude is specified, return all types
        if (!$includeTypes && !$excludeTypes) {
            return $allTypes;
        }

        // Handle include option
        if ($includeTypes) {
            $types = array_map('trim', explode(',', $includeTypes));
            $parsedTypes = [];

            foreach ($types as $typeString) {
                $type = $this->parsePackageManagerType($typeString);
                if ($type === null) {
                    $output->writeln("<error>Invalid package manager type: '{$typeString}'. Valid types: composer, npmjs</error>");
                    return null;
                }
                $parsedTypes[] = $type;
            }

            return $parsedTypes;
        }

        // Handle exclude option
        $excludeTypeStrings = array_map('trim', explode(',', $excludeTypes));
        $excludeTypesArray = [];

        foreach ($excludeTypeStrings as $typeString) {
            $type = $this->parsePackageManagerType($typeString);
            if ($type === null) {
                $output->writeln("<error>Invalid package manager type: '{$typeString}'. Valid types: composer, npmjs</error>");
                return null;
            }
            $excludeTypesArray[] = $type;
        }

        // Return all types except the excluded ones
        return array_filter($allTypes, fn (PackageManagerType $type) => !in_array($type, $excludeTypesArray));
    }

    private function parsePackageManagerType(string $typeString): ?PackageManagerType
    {
        return match (strtolower($typeString)) {
            'composer' => PackageManagerType::COMPOSER,
            'npmjs', 'npm' => PackageManagerType::NPM,
            default => null,
        };
    }
}
