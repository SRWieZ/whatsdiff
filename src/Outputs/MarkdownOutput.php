<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Enums\Semver;

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
            $output->writeln('');
            $output->writeln('| Package | Version |');
            $output->writeln('|---------|---------|');
            foreach ($added as $change) {
                $output->writeln("| **{$change->name}** | `{$change->to}` |");
            }
            $output->writeln('');
        }

        // Removed packages
        $removed = $diff->getRemovedPackages();
        if ($removed->isNotEmpty()) {
            $output->writeln('### Removed');
            $output->writeln('');
            $output->writeln('| Package | Version |');
            $output->writeln('|---------|---------|');
            foreach ($removed as $change) {
                $output->writeln("| **{$change->name}** | `{$change->from}` |");
            }
            $output->writeln('');
        }

        // Updated packages
        $updated = $diff->getUpdatedPackages();
        if ($updated->isNotEmpty()) {
            $output->writeln('### Updated');
            $output->writeln('');
            $output->writeln('| Package | From | To | Change | Releases |');
            $output->writeln('|---------|------|----|--------|----------|');
            foreach ($updated as $change) {
                $semverBadge = $this->getSemverEmoji($change->semver);
                $releaseText = $this->getReleaseText($change->releaseCount);
                $output->writeln("| **{$change->name}** | `{$change->from}` | `{$change->to}` | {$semverBadge} | {$releaseText} |");
            }
            $output->writeln('');
        }

        // Downgraded packages
        $downgraded = $diff->getDowngradedPackages();
        if ($downgraded->isNotEmpty()) {
            $output->writeln('### Downgraded');
            $output->writeln('');
            $output->writeln('| Package | From | To | Change | Releases |');
            $output->writeln('|---------|------|----|--------|----------|');
            foreach ($downgraded as $change) {
                $semverBadge = $this->getSemverEmoji($change->semver);
                $releaseText = $this->getReleaseText($change->releaseCount);
                $output->writeln("| **{$change->name}** | `{$change->from}` | `{$change->to}` | {$semverBadge} | {$releaseText} |");
            }
            $output->writeln('');
        }
    }

    // private function getSemverBadge(?Semver $semver): string
    // {
    //     if ($semver === null) {
    //         return '';
    //     }
    //
    //     return match ($semver) {
    //         Semver::Major => '![major](https://img.shields.io/badge/major-red)',
    //         Semver::Minor => '![minor](https://img.shields.io/badge/minor-orange)',
    //         Semver::Patch => '![patch](https://img.shields.io/badge/patch-green)',
    //     };
    // }

    private function getSemverEmoji(?Semver $semver): string
    {
        if ($semver === null) {
            return '';
        }

        return match ($semver) {
            Semver::Major => '🔴 Major',
            Semver::Minor => '🟡 Minor',
            Semver::Patch => '🟢 Patch',
        };
    }

    private function getReleaseText(?int $releaseCount): string
    {
        if ($releaseCount === null || $releaseCount === 0) {
            return '';
        }

        // return $releaseCount === 1 ? '1 release' : "{$releaseCount} releases";
        return (string) $releaseCount;
    }
}
