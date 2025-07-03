<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Data\PackageChange;
use Whatsdiff\Enums\ChangeStatus;
use Whatsdiff\Enums\Semver;
use Whatsdiff\Services\VersionHighlighter;

class TextOutput implements OutputFormatterInterface
{
    private bool $useAnsi;
    private VersionHighlighter $versionHighlighter;

    public function __construct(bool $useAnsi = true)
    {
        $this->useAnsi = $useAnsi;
        $this->versionHighlighter = new VersionHighlighter();
    }

    public function format(DiffResult $result, OutputInterface $output): void
    {
        if ( ! $result->hasDiffs()) {
            $filenameList = collect(PackageManagerType::cases())
                ->map(fn($type) => $type->getLockFileName())
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
            $output->writeln($diff->filename.$commitText);
        } else {
            $fromCommit = $diff->fromCommit ?? 'unknown';
            $toCommit = $diff->toCommit ?? 'uncommitted changes';
            $output->writeln("{$diff->filename} between {$fromCommit} and {$toCommit}");
        }
        $output->writeln('');

        if ( ! $diff->hasChanges()) {
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
        $maxNameLen = $diff->changes->max(fn(PackageChange $c) => strlen($c->name)) ?: 0;
        $maxFromLen = $diff->changes
            ->filter(fn(PackageChange $c) => $c->from !== null)
            ->max(fn(PackageChange $c) => strlen($c->from)) ?: 0;
        $maxToLen = $diff->changes
            ->filter(fn(PackageChange $c) => $c->to !== null)
            ->max(fn(PackageChange $c) => strlen($c->to)) ?: 0;

        foreach ($changes as $change) {
            $symbol = $this->getSymbol($change->status, $change->semver);
            $line = $symbol;

            // if ($change->semver !== null) {
            //     $semver = match ($change->semver) {
            //         Semver::Major => ($this->useAnsi ? "\033[31mmajor\033[0m" : 'M'),
            //         Semver::Minor => ($this->useAnsi ? "\033[38;5;208mminor\033[0m" : 'm'),
            //         Semver::Patch => ($this->useAnsi ? "\033[32mpatch\033[0m" : 'p'),
            //     };
            //     $line .= "  {$semver} ";
            // } else {
            //     $line .= '        ';
            // }

            $line .= ' '.str_pad($change->name, $maxNameLen).'    ';

            switch ($change->status) {
                case ChangeStatus::Added:
                    $line .= $change->to;
                    break;
                case ChangeStatus::Removed:
                    $line .= $change->from;
                    break;
                case ChangeStatus::Updated:
                case ChangeStatus::Downgraded:
                    $fromVersion = str_pad($change->from, $maxFromLen);
                    $toVersion = str_pad($change->to, $maxToLen);

                    $line .= $fromVersion.'  →  '.$toVersion;
                    if ($change->releaseCount > 1) {
                        $line .= "  ({$change->releaseCount} releases)";
                    }
                    break;
            }

            $output->writeln($line);
        }
    }

    private function getSymbol(ChangeStatus $status, ?Semver $semver): string
    {
        $repeat = match ($semver) {
            Semver::Major => 3,
            Semver::Minor => 2,
            Semver::Patch => 1,
            default => 1,
        };

        $symbol = match ($status) {
            ChangeStatus::Added => '+',
            ChangeStatus::Removed => '×',
            ChangeStatus::Updated => str_repeat('↑', $repeat),
            ChangeStatus::Downgraded => str_repeat('↓', $repeat),
        };

        $symbol = mb_str_pad($symbol, 4, ' ', STR_PAD_LEFT);

        if ( ! $this->useAnsi) {
            return $symbol;
        }

        return match ($status) {
            ChangeStatus::Added => "\033[32m{$symbol}\033[0m",
            ChangeStatus::Removed => "\033[31m{$symbol}\033[0m",
            ChangeStatus::Updated => "\033[36m{$symbol}\033[0m",
            ChangeStatus::Downgraded => "\033[33m{$symbol}\033[0m",
        };
    }
}
