<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Whatsdiff\Container\Container;
use Whatsdiff\Services\ConfigService;

#[AsCommand(
    name: 'config',
    description: 'Get or set configuration values',
    hidden: false,
)]
class ConfigCommand extends Command
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
            ->setHelp('This command allows you to get or set configuration values')
            ->addArgument(
                'key',
                InputArgument::OPTIONAL,
                'Configuration key (use dot notation for nested values)'
            )
            ->addArgument(
                'value',
                InputArgument::OPTIONAL,
                'Value to set'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configService = $this->container->get(ConfigService::class);

        $key = $input->getArgument('key');
        $value = $input->getArgument('value');

        // If no arguments, show all config
        if ($key === null) {
            $config = $configService->getAll();
            $output->writeln(Yaml::dump($config, 4, 2));
            return Command::SUCCESS;
        }

        // If only key provided, get the value
        if ($value === null) {
            $configValue = $configService->get($key);

            if ($configValue === null) {
                $output->writeln("<error>Configuration key '{$key}' not found</error>");
                return Command::FAILURE;
            }

            if (is_array($configValue)) {
                $output->writeln(Yaml::dump($configValue, 4, 2));
            } else {
                $output->writeln((string) $configValue);
            }

            return Command::SUCCESS;
        }

        // Set the value
        $parsedValue = $this->parseValue($value);
        $configService->set($key, $parsedValue);

        $output->writeln("<info>Configuration updated: {$key} = {$value}</info>");

        return Command::SUCCESS;
    }

    private function parseValue(string $value): mixed
    {
        // Handle boolean values
        if (strtolower($value) === 'true') {
            return true;
        }
        if (strtolower($value) === 'false') {
            return false;
        }

        // Handle numeric values
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            }
            return (int) $value;
        }

        // Everything else is a string
        return $value;
    }
}
