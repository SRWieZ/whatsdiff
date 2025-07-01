<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\Comparator;
use Illuminate\Support\Collection;
use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DependencyFile;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Data\PackageChange;

class DiffCalculator
{
    private GitRepository $git;
    private ComposerAnalyzer $composerAnalyzer;
    private NpmAnalyzer $npmAnalyzer;

    private Collection $dependencyFiles;

    public function __construct(
        GitRepository $git,
        ComposerAnalyzer $composerAnalyzer,
        NpmAnalyzer $npmAnalyzer
    ) {
        $this->git = $git;
        $this->composerAnalyzer = $composerAnalyzer;
        $this->npmAnalyzer = $npmAnalyzer;
        $this->dependencyFiles = collect();
        $this->initializeDependencyFilesStructure();
    }

    private function initializeDependencyFilesStructure(): void
    {
        foreach (PackageManagerType::cases() as $type) {
            $this->dependencyFiles->push(
                DependencyFile::create($type, $type->getLockFileName())
            );
        }
    }

    public function calculateDiffs(bool $ignoreLast, bool $skipReleaseCount = false, ?string $fromCommit = null, ?string $toCommit = null): DiffResult
    {
        $diffs = collect();

        // Handle custom commits case
        if ($fromCommit !== null || $toCommit !== null) {
            $fromHash = $fromCommit ? $this->git->resolveCommitHash($fromCommit) : null;
            $toHash = $toCommit ? $this->git->resolveCommitHash($toCommit) : $this->git->resolveCommitHash('HEAD');

            foreach (PackageManagerType::cases() as $type) {
                $filename = $type->getLockFileName();
                $diff = $this->calculateDiffBetweenCommits(
                    $type,
                    $filename,
                    $fromHash,
                    $toHash,
                    $skipReleaseCount
                );

                if ($diff !== null) {
                    $diffs->push($diff);
                }
            }

            return new DiffResult($diffs, false);
        }

        // Handle normal flow
        $this->initializeDependencyFiles($ignoreLast);

        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
        $hasCommitLogs = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasCommitLogs);

        // Case 1: No recent changes and no commit logs
        if (!$recentlyUpdated && !$hasCommitLogs) {
            return new DiffResult($diffs, false);
        }

        $relevantFiles = $recentlyUpdated
            ? $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated)
            : $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasCommitLogs);

        $filenames = $relevantFiles->pluck('file')->toArray();
        $commitLogs = $this->git->getMultipleFilesCommitLogs($filenames);

        foreach ($relevantFiles as $dependencyFile) {
            $diff = $this->processDependencyFile(
                $dependencyFile->type,
                $dependencyFile->file,
                $recentlyUpdated,
                $commitLogs,
                $skipReleaseCount
            );
            if ($diff !== null) {
                $diffs->push($diff);
            }
        }

        return new DiffResult($diffs, $recentlyUpdated);
    }

    private function calculateDiffBetweenCommits(
        PackageManagerType $type,
        string $filename,
        ?string $fromHash,
        ?string $toHash,
        bool $skipReleaseCount = false,
        bool $isNew = false
    ): ?DependencyDiff {
        // Get file content for both commits
        $toContent = $toHash ? $this->git->getFileContentAtCommit($filename, $toHash) : file_get_contents(basename($filename));
        $fromContent = $fromHash ? $this->git->getFileContentAtCommit($filename, $fromHash) : null;

        if (empty($toContent)) {
            return null;
        }

        // Calculate the diff
        $packageDiffs = $this->calculatePackageDiff($type, $toContent, $fromContent);
        $changes = $this->convertToPackageChanges($packageDiffs, $type, $skipReleaseCount);

        if ($changes->isEmpty()) {
            return null;
        }

        // Use short hashes for display
        $fromHashShort = $fromHash ? $this->git->getShortCommitHash($fromHash) : null;
        $toHashShort = $toHash ? $this->git->getShortCommitHash($toHash) : null;

        return new DependencyDiff(
            filename: $filename,
            type: $type,
            fromCommit: $fromHashShort,
            toCommit: $toHashShort,
            changes: $changes,
            isNew: $isNew
        );
    }


    private function initializeDependencyFiles(bool $ignoreLast): void
    {
        $relativeCurrentDir = $this->git->getRelativeCurrentDir();

        // Adjust file paths relative to current directory and git root
        $this->dependencyFiles = $this->dependencyFiles->map(function (DependencyFile $dependencyFile) use ($relativeCurrentDir) {
            if (!empty($relativeCurrentDir) && file_exists($dependencyFile->file)) {
                return $dependencyFile->withFile($relativeCurrentDir . DIRECTORY_SEPARATOR . $dependencyFile->file);
            }
            return $dependencyFile;
        });

        // Check if files have been recently updated or have commit logs
        $this->dependencyFiles = $this->dependencyFiles->map(function (DependencyFile $dependencyFile) use ($ignoreLast) {
            $hasBeenRecentlyUpdated = !$ignoreLast && $this->git->isFileRecentlyUpdated($dependencyFile->file);
            $commitLogs = $this->git->getFileCommitLogs($dependencyFile->file);
            $hasCommitLogs = !empty($commitLogs);

            return $dependencyFile->withUpdatedStatus($hasBeenRecentlyUpdated, $hasCommitLogs, $commitLogs);
        });
    }

    private function processDependencyFile(
        PackageManagerType $type,
        string $filename,
        bool $recentlyUpdated,
        array $commitLogs,
        bool $skipReleaseCount = false
    ): ?DependencyDiff {
        $dependencyFile = $this->dependencyFiles->first(fn (DependencyFile $file) => $file->type === $type);
        $commitLogsToCompare = $dependencyFile->commitLogs;

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

        return $this->calculateDiffBetweenCommits(
            $type,
            $filename,
            $previousHashOrNot,
            $lastHash,
            $skipReleaseCount,
            $isNew
        );
    }

    private function getCommitHashToCompare(array $commitLogs, bool $recentlyUpdated): array
    {
        $last = $recentlyUpdated ? null : $commitLogs[0];
        $previousHashKey = $recentlyUpdated ? 0 : 1;
        $previous = $commitLogs[$previousHashKey] ?? null;

        return [$last, $previous];
    }


    private function calculatePackageDiff(PackageManagerType $type, string $last, ?string $previous): array
    {
        return match ($type) {
            PackageManagerType::COMPOSER => $this->composerAnalyzer->calculateDiff($last, $previous),
            PackageManagerType::NPM => $this->npmAnalyzer->calculateDiff($last, $previous),
        };
    }

    private function convertToPackageChanges(array $diff, PackageManagerType $type, bool $skipReleaseCount = false): Collection
    {
        $changes = collect();

        foreach ($diff as $package => $infos) {
            if ($infos['from'] !== null && $infos['to'] !== null) {
                if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                    $releasesCount = $skipReleaseCount ? null : $this->getReleasesCount($type, $package, $infos);
                    $changes->push(PackageChange::updated(
                        name: $package,
                        type: $type,
                        fromVersion: $infos['from'],
                        toVersion: $infos['to'],
                        releaseCount: $releasesCount,
                    ));
                } else {
                    $releasesCount = $skipReleaseCount ? null : $this->getReleasesCount($type, $package, $infos);
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

    private function getReleasesCount(PackageManagerType $type, string $package, array $infos): int
    {
        return match ($type) {
            PackageManagerType::COMPOSER => $this->composerAnalyzer->getReleasesCount(
                $package,
                $infos['from'],
                $infos['to'],
                $infos['infos_url']
            ),
            PackageManagerType::NPM => $this->npmAnalyzer->getReleasesCount($package, $infos['from'], $infos['to']),
        };
    }
}
