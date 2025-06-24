<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\Comparator;
use Illuminate\Support\Collection;
use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Data\PackageChange;

class DiffCalculator
{
    private GitRepository $git;
    private ComposerAnalyzer $composerAnalyzer;
    private NpmAnalyzer $npmAnalyzer;

    private array $dependencyFiles = [
        'composer' => [
            'file' => 'composer.lock',
            'type' => PackageManagerType::COMPOSER->value,
            'hasBeenRecentlyUpdated' => false,
            'hasCommitLogs' => false,
            'commitLogs' => [],
        ],
        'npmjs' => [
            'file' => 'package-lock.json',
            'type' => PackageManagerType::NPM->value,
            'hasBeenRecentlyUpdated' => false,
            'hasCommitLogs' => false,
            'commitLogs' => [],
        ],
    ];

    public function __construct(
        GitRepository $git,
        ComposerAnalyzer $composerAnalyzer,
        NpmAnalyzer $npmAnalyzer
    ) {
        $this->git = $git;
        $this->composerAnalyzer = $composerAnalyzer;
        $this->npmAnalyzer = $npmAnalyzer;
    }

    public function calculateDiffs(bool $ignoreLast): DiffResult
    {
        $this->initializeDependencyFiles($ignoreLast);

        $dependencyFiles = collect($this->dependencyFiles);

        $recentlyUpdated = $dependencyFiles->contains(fn ($file) => $file['hasBeenRecentlyUpdated'], true);
        $hasCommitLogs = $dependencyFiles->contains(fn ($file) => $file['hasCommitLogs'], true);

        $diffs = collect();

        // Case 1: No recent changes and no commit logs
        if (!$recentlyUpdated && !$hasCommitLogs) {
            return new DiffResult($diffs, false);
        }

        if ($recentlyUpdated) {
            $filenames = $dependencyFiles
                ->where('hasBeenRecentlyUpdated', true)
                ->pluck('file', 'type')->toArray();
        } else {
            $filenames = $dependencyFiles
                ->where('hasCommitLogs', true)
                ->pluck('file', 'type')->toArray();
        }

        $commitLogs = $this->git->getMultipleFilesCommitLogs($filenames);

        foreach ($filenames as $type => $filename) {
            $diff = $this->processDependencyFile($type, $filename, $recentlyUpdated, $commitLogs);
            if ($diff !== null) {
                $diffs->push($diff);
            }
        }

        return new DiffResult($diffs, $recentlyUpdated);
    }

    private function initializeDependencyFiles(bool $ignoreLast): void
    {
        $relativeCurrentDir = $this->git->getRelativeCurrentDir();


        // Adjust file paths relative to current directory and git root
        foreach ($this->dependencyFiles as $key => $file) {
            if (!empty($relativeCurrentDir) && file_exists($file['file'])) {
                $this->dependencyFiles[$key]['file'] = $relativeCurrentDir . DIRECTORY_SEPARATOR . $file['file'];
            }
            
        }

        foreach ($this->dependencyFiles as $type => $file) {
            $this->dependencyFiles[$type]['hasBeenRecentlyUpdated'] =
                !$ignoreLast && $this->git->isFileRecentlyUpdated($file['file']);

            $commitLogs = $this->git->getFileCommitLogs($file['file']);
            $this->dependencyFiles[$type]['hasCommitLogs'] = !empty($commitLogs);
            $this->dependencyFiles[$type]['commitLogs'] = $commitLogs;
            
        }
    }

    private function processDependencyFile(
        string $type,
        string $filename,
        bool $recentlyUpdated,
        array $commitLogs
    ): ?DependencyDiff {
        $commitLogsToCompare = $recentlyUpdated
            ? $this->dependencyFiles[$type]['commitLogs']
            : $commitLogs;

        [$lastHash, $previousHash] = $this->getCommitHashToCompare($commitLogsToCompare, $recentlyUpdated);

        $existInPreviousHash = collect($commitLogsToCompare)->contains($previousHash);
        $previousHashOrNot = $existInPreviousHash ? $previousHash : null;

        $isNew = false;
        if ($previousHashOrNot === null) {
            $commitPriorToLast = $previousHash
                ? $this->git->getFileCommitLogs($filename, $previousHash)
                : [];
            $isNew = count($commitPriorToLast) === 0;

            if (!$isNew) {
                // File has been untouched
                return null;
            }
        }

        [$last, $previous] = $this->getFilesToCompare($filename, $lastHash, $previousHashOrNot);

        if (empty($last)) {
            return null;
        }

        $packageDiffs = $this->calculatePackageDiff($type, $last, $previous);
        $changes = $this->convertToPackageChanges($packageDiffs, $type);

        return new DependencyDiff(
            filename: $filename,
            type: $type,
            fromCommit: $previousHash,
            toCommit: $lastHash,
            changes: $changes,
            isNew: $isNew,
        );
    }

    private function getCommitHashToCompare(array $commitLogs, bool $recentlyUpdated): array
    {
        $last = $recentlyUpdated ? null : $commitLogs[0];
        $previousHashKey = $recentlyUpdated ? 0 : 1;
        $previous = $commitLogs[$previousHashKey] ?? null;

        return [$last, $previous];
    }

    private function getFilesToCompare(string $filename, ?string $lastHash, ?string $previousHash): array
    {
        $last = $lastHash
            ? $this->git->getFileContentAtCommit($filename, $lastHash)
            : file_get_contents(basename($filename));

        $previous = $previousHash
            ? $this->git->getFileContentAtCommit($filename, $previousHash)
            : null;

        return [$last, $previous];
    }

    private function calculatePackageDiff(string $type, string $last, ?string $previous): array
    {
        return match ($type) {
            'composer' => $this->composerAnalyzer->calculateDiff($last, $previous),
            'npmjs' => $this->npmAnalyzer->calculateDiff($last, $previous),
            default => [],
        };
    }

    private function convertToPackageChanges(array $diff, string $type): Collection
    {
        $changes = collect();

        foreach ($diff as $package => $infos) {
            if ($infos['from'] !== null && $infos['to'] !== null) {
                if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                    $releasesCount = $this->getReleasesCount($type, $package, $infos);
                    $changes->push(PackageChange::updated(
                        name: $package,
                        type: $type,
                        fromVersion: $infos['from'],
                        toVersion: $infos['to'],
                        releaseCount: $releasesCount,
                    ));
                } else {
                    $releasesCount = $this->getReleasesCount($type, $package, $infos);
                    $changes->push(PackageChange::downgraded(
                        name: $package,
                        type: $type,
                        fromVersion: $infos['from'],
                        toVersion: $infos['to'],
                        releaseCount: $releasesCount,
                    ));
                }
            } elseif ($infos['from'] === null && $infos['to'] !== null) {
                $changes->push(PackageChange::added(
                    name: $package,
                    type: $type,
                    version: $infos['to'],
                ));
            } elseif ($infos['from'] !== null && $infos['to'] === null) {
                $changes->push(PackageChange::removed(
                    name: $package,
                    type: $type,
                    version: $infos['from'],
                ));
            }
        }

        return $changes;
    }

    private function getReleasesCount(string $type, string $package, array $infos): int
    {
        return match ($type) {
            'composer' => $this->composerAnalyzer->getReleasesCount(
                $package,
                $infos['from'],
                $infos['to'],
                $infos['infos_url']
            ),
            'npmjs' => $this->npmAnalyzer->getReleasesCount($package, $infos['from'], $infos['to']),
            default => 0,
        };
    }
}
