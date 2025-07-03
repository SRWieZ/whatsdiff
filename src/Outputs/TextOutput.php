<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Data\PackageChange;
use Whatsdiff\Enums\ChangeStatus;

class TextOutput implements OutputFormatterInterface
{
    private bool $useAnsi;

    public function __construct(bool $useAnsi = true)
    {
        $this->useAnsi = $useAnsi;
    }

    public function format(DiffResult $result, OutputInterface $output): void
    {
        if (!$result->hasDiffs()) {
            $filenameList = collect(PackageManagerType::cases())
                ->map(fn ($type) => $type->getLockFileName())
                ->implode(', ');
            $output->writeln("No recent changes and no commit logs found for {$filenameList}");
            return;
        }

        if ($result->hasUncommittedChanges) {
            $filenames = $result->diffs
                ->pluck('filename')
                ->implode(', ');
            $output->writeln("Uncommitted changes detected on {$filenames}");
            $output->writeln('');
        }

        foreach ($result->diffs as $diff) {
            $this->formatDiff($diff, $output);
        }
    }

    private function formatDiff(DependencyDiff $diff, OutputInterface $output): void
    {
        if ($diff->isNew) {
            $commitText = $diff->toCommit ? " created at {$diff->toCommit}" : ' created';
            $output->writeln($diff->filename . $commitText);
        } else {
            $fromCommit = $diff->fromCommit ?? 'unknown';
            $toCommit = $diff->toCommit ?? 'uncommitted changes';
            $output->writeln("{$diff->filename} between {$fromCommit} and {$toCommit}");
        }
        $output->writeln('');

        if (!$diff->hasChanges()) {
            $output->writeln(' → No dependencies changes detected');
            $output->writeln('');
            return;
        }

        $this->printChanges($diff, $output);
        $output->writeln('');
    }

    private function printChanges(DependencyDiff $diff, OutputInterface $output): void
    {
        $changes = $diff->changes->all();

        if (empty($changes)) {
            return;
        }

        // Calculate padding for alignment
        $maxNameLen = $diff->changes->max(fn (PackageChange $c) => strlen($c->name)) ?: 0;
        $maxFromLen = $diff->changes
            ->filter(fn (PackageChange $c) => $c->from !== null)
            ->max(fn (PackageChange $c) => strlen($c->from)) ?: 0;

        foreach ($changes as $change) {
            $symbol = $this->getSymbol($change->status);
            $line = $symbol . ' ' . str_pad($change->name, $maxNameLen) . ' : ';

            switch ($change->status) {
                case ChangeStatus::Added:
                    $line .= $change->to;
                    break;
                case ChangeStatus::Removed:
                    $line .= $change->from;
                    break;
                case ChangeStatus::Updated:
                case ChangeStatus::Downgraded:
                    $line .= str_pad($change->from, $maxFromLen) . ' => ' . $change->to;
                    if ($change->releaseCount > 1) {
                        $line .= " ({$change->releaseCount} releases)";
                    }
                    break;
            }

            $output->writeln($line);
        }
    }

    private function getSymbol(ChangeStatus $status): string
    {
        if (!$this->useAnsi) {
            return match ($status) {
                ChangeStatus::Added => '+',
                ChangeStatus::Removed => '×',
                ChangeStatus::Updated => '↑',
                ChangeStatus::Downgraded => '↓',
            };
        }

        return match ($status) {
            ChangeStatus::Added => "\033[32m+\033[0m",
            ChangeStatus::Removed => "\033[31m×\033[0m",
            ChangeStatus::Updated => "\033[36m↑\033[0m",
            ChangeStatus::Downgraded => "\033[33m↓\033[0m",
        };
    }
}
