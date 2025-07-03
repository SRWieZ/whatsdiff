<?php

declare(strict_types=1);

namespace Whatsdiff\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            );

        // Add shared options from AnalyseCommand
        foreach (AnalyseCommand::getSharedOptions() as $option) {
            $this->addOption(...$option);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Passthrough the input options to the Analyse command
        $options = array_filter($input->getOptions(), fn ($value) => $value !== null);
        $options = array_combine(
            keys: array_map(fn ($key) => '--' . $key, array_keys($options)),
            values: $options
        );

        $runInput = new ArrayInput(
            array_merge(
                $options,
                [
                    '--from' => $input->getArgument('from'),
                    '--to' => $input->getArgument('to'),
                ]
            )
        );

        // Get the Analyse command and execute it
        $analyseCommand = $this->getApplication()->find('analyse');

        return $analyseCommand->run($runInput, $output);
    }
}
