<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;

class MarkdownOutput implements OutputFormatterInterface
{
    public function format(DiffResult $result, OutputInterface $output): void
    {
        if (!$result->hasAnyChanges()) {
            $output->writeln('No dependency changes detected.');
            return;
        }

        $output->writeln('# Dependency Changes');
        $output->writeln('');

        if ($result->hasUncommittedChanges) {
            $output->writeln('> **Note:** Showing uncommitted changes');
            $output->writeln('');
        }

        foreach ($result->diffs as $diff) {
            $this->formatDiff($diff, $output);
        }
    }

    private function formatDiff(DependencyDiff $diff, OutputInterface $output): void
    {
        if (!$diff->hasChanges()) {
            return;
        }

        $output->writeln("## {$diff->filename}");

        if ($diff->isNew) {
            $output->writeln('*File created*');
        } else {
            $fromCommit = $diff->fromCommit ? substr($diff->fromCommit, 0, 7) : 'unknown';
            $toCommit = $diff->toCommit ? substr($diff->toCommit, 0, 7) : 'uncommitted';
            $output->writeln("*Changes from `{$fromCommit}` to `{$toCommit}`*");
        }

        $output->writeln('');

        // Added packages
        $added = $diff->getAddedPackages();
        if ($added->isNotEmpty()) {
            $output->writeln('### Added');
            foreach ($added as $change) {
                $output->writeln("- **{$change->name}** `{$change->to}`");
            }
            $output->writeln('');
        }

        // Removed packages
        $removed = $diff->getRemovedPackages();
        if ($removed->isNotEmpty()) {
            $output->writeln('### Removed');
            foreach ($removed as $change) {
                $output->writeln("- **{$change->name}** `{$change->from}`");
            }
            $output->writeln('');
        }

        // Updated packages
        $updated = $diff->getUpdatedPackages();
        if ($updated->isNotEmpty()) {
            $output->writeln('### Updated');
            foreach ($updated as $change) {
                $releaseText = $change->releaseCount > 1 ? " ({$change->releaseCount} releases)" : '';
                $output->writeln("- **{$change->name}** `{$change->from}` → `{$change->to}`{$releaseText}");
            }
            $output->writeln('');
        }

        // Downgraded packages
        $downgraded = $diff->getDowngradedPackages();
        if ($downgraded->isNotEmpty()) {
            $output->writeln('### Downgraded');
            foreach ($downgraded as $change) {
                $releaseText = $change->releaseCount > 1 ? " ({$change->releaseCount} releases)" : '';
                $output->writeln("- **{$change->name}** `{$change->from}` → `{$change->to}`{$releaseText}");
            }
            $output->writeln('');
        }
    }
}
