<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'between')]
class BetweenCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setDescription('Compare dependency changes between two commits, branches, or tags')
            ->setHelp('This command provides a convenient way to compare dependency changes between two specific points in your project history. It internally uses the diff command with --from and --to options.')
            ->addArgument(
                'from',
                InputArgument::REQUIRED,
                'The starting commit, branch, or tag to compare from (older version)'
            )
            ->addArgument(
                'to',
                InputArgument::OPTIONAL,
                'The ending commit, branch, or tag to compare to (newer version, defaults to HEAD)',
                'HEAD'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format (text, json, markdown)',
                'text'
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
        $from = $input->getArgument('from');
        $to = $input->getArgument('to');
        $format = $input->getOption('format');
        $noCache = $input->getOption('no-cache');

        // Prepare arguments for the diff command
        $diffArguments = [
            'command' => 'diff',
            '--from' => $from,
            '--to' => $to,
            '--format' => $format,
        ];

        if ($noCache) {
            $diffArguments['--no-cache'] = true;
        }

        // Create input for the diff command
        $diffInput = new ArrayInput($diffArguments);

        // Get the diff command and execute it
        $diffCommand = $this->getApplication()->find('diff');

        return $diffCommand->run($diffInput, $output);
    }
}
