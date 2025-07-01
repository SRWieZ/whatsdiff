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

    // Fluent interface state
    private ?string $fromCommit = null;
    private ?string $toCommit = null;
    private bool $ignoreLast = false;
    private bool $skipReleaseCount = false;
    private ?DiffResult $diffResult = null;

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

    /**
     * Set the from commit for comparison
     */
    public function fromCommit(?string $commit): self
    {
        $this->fromCommit = $commit;

        return $this;
    }

    /**
     * Set the to commit for comparison
     */
    public function toCommit(?string $commit): self
    {
        $this->toCommit = $commit;

        return $this;
    }

    /**
     * Enable ignoring the last commit
     */
    public function ignoreLastCommit(bool $ignore = true): self
    {
        $this->ignoreLast = $ignore;

        return $this;
    }

    /**
     * Enable skipping release count fetching for performance
     */
    public function skipReleaseCount(bool $skip = true): self
    {
        $this->skipReleaseCount = $skip;

        return $this;
    }

    /**
     * Run the diff calculation
     *
     * @param  bool  $withProgress  If true, returns [totalCount, generator], otherwise returns DiffResult
     * @return DiffResult|array{int, \Generator<PackageChange>}
     */
    public function run(bool $withProgress = false)
    {
        if ($withProgress) {
            return $this->runWithProgress();
        }

        [$totalCount, $generator] = $this->runWithProgress();

        foreach ($generator as $packageChange) {
            // Just iterate through the generator to trigger the diff calculation
        }

        return $this->diffResult;
    }

    /**
     * Get the result of the last run() call
     */
    public function getResult(): ?DiffResult
    {
        return $this->diffResult;
    }

    /**
     * Run with progress reporting using generator
     *
     * @return array{int, \Generator<PackageChange>}
     */
    private function runWithProgress(): array
    {
        $generator = $this->processDiffsWithProgress();
        $totalCount = $this->calculatePackageChangesCount();

        return [$totalCount, $generator];
    }

    /**
     * Calculate exact total number of package changes that will be processed
     * Does a fast pass with skipReleaseCount to avoid HTTP requests
     */
    private function calculatePackageChangesCount(): int
    {
        $totalCount = 0;

        // Handle custom commits case
        if ($this->fromCommit !== null || $this->toCommit !== null) {
            $fromHash = $this->fromCommit ? $this->git->resolveCommitHash($this->fromCommit) : null;
            $toHash = $this->toCommit ? $this->git->resolveCommitHash($this->toCommit) : $this->git->resolveCommitHash('HEAD');

            foreach (PackageManagerType::cases() as $type) {
                $filename = $type->getLockFileName();
                $toContent = $toHash ? $this->git->getFileContentAtCommit(
                    $filename,
                    $toHash
                ) : file_get_contents(basename($filename));
                $fromContent = $fromHash ? $this->git->getFileContentAtCommit($filename, $fromHash) : null;

                if (! empty($toContent)) {
                    $packageDiffs = $this->calculatePackageDiff($type, $toContent, $fromContent);
                    $totalCount += count($packageDiffs);
                }
            }

            return $totalCount;
        }

        // Handle normal flow
        $this->initializeDependencyFiles($this->ignoreLast);

        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
        $hasCommitLogs = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasCommitLogs);

        if (! $recentlyUpdated && ! $hasCommitLogs) {
            return 0;
        }

        $relevantFiles = $recentlyUpdated
            ? $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated)
            : $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasCommitLogs);

        $filenames = $relevantFiles->pluck('file')->toArray();
        $commitLogs = $this->git->getMultipleFilesCommitLogs($filenames);

        foreach ($relevantFiles as $dependencyFile) {
            $dependencyFileFromCollection = $this->dependencyFiles->first(fn (
                DependencyFile $file
            ) => $file->type === $dependencyFile->type);
            $commitLogsToCompare = $dependencyFileFromCollection->commitLogs;

            [$lastHash, $previousHash] = $this->getCommitHashToCompare($commitLogsToCompare, $recentlyUpdated);

            $existInPreviousHash = collect($commitLogsToCompare)->contains($previousHash);
            $previousHashOrNot = $existInPreviousHash ? $previousHash : null;

            // Skip if file hasn't changed
            if ($previousHashOrNot === null) {
                $commitPriorToLast = $previousHash
                    ? $this->git->getFileCommitLogs($dependencyFile->file, $previousHash)
                    : [];
                $isNew = count($commitPriorToLast) === 0;

                if (! $isNew) {
                    continue;
                }
            }

            // Get file contents and calculate diff
            $toContent = $lastHash
                ? $this->git->getFileContentAtCommit($dependencyFile->file, $lastHash)
                : file_get_contents(basename($dependencyFile->file));
            $fromContent = $previousHashOrNot
                ? $this->git->getFileContentAtCommit($dependencyFile->file, $previousHashOrNot)
                : null;

            if (! empty($toContent)) {
                $packageDiffs = $this->calculatePackageDiff($dependencyFile->type, $toContent, $fromContent);
                $totalCount += count($packageDiffs);
            }
        }

        return $totalCount;
    }

    /**
     * Process diffs with progress reporting
     *
     * @return \Generator<PackageChange>
     */
    private function processDiffsWithProgress(): \Generator
    {
        $diffs = collect();

        // Handle custom commits case
        if ($this->fromCommit !== null || $this->toCommit !== null) {
            $fromHash = $this->fromCommit ? $this->git->resolveCommitHash($this->fromCommit) : null;
            $toHash = $this->toCommit ? $this->git->resolveCommitHash($this->toCommit) : $this->git->resolveCommitHash('HEAD');

            foreach (PackageManagerType::cases() as $type) {
                $filename = $type->getLockFileName();
                $generator = $this->calculateDiffBetweenCommitsWithProgress(
                    $type,
                    $filename,
                    $fromHash,
                    $toHash,
                    $this->skipReleaseCount
                );

                $changes = collect();
                foreach ($generator as $packageChange) {
                    $changes->push($packageChange);
                    yield $packageChange;
                }

                // Get the return value (DependencyDiff) from the generator
                $diff = $generator->getReturn();
                if ($diff instanceof DependencyDiff) {
                    $diffs->push($diff);
                }
            }

            $this->diffResult = new DiffResult($diffs, false);

            return;
        }

        // Handle normal flow
        $this->initializeDependencyFiles($this->ignoreLast);

        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
        $hasCommitLogs = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasCommitLogs);

        // Case 1: No recent changes and no commit logs
        if (! $recentlyUpdated && ! $hasCommitLogs) {
            $this->diffResult = new DiffResult($diffs, false);

            return;
        }

        $relevantFiles = $recentlyUpdated
            ? $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated)
            : $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasCommitLogs);

        $filenames = $relevantFiles->pluck('file')->toArray();
        $commitLogs = $this->git->getMultipleFilesCommitLogs($filenames);

        foreach ($relevantFiles as $dependencyFile) {
            $dependencyFileFromCollection = $this->dependencyFiles->first(fn (
                DependencyFile $file
            ) => $file->type === $dependencyFile->type);
            $commitLogsToCompare = $dependencyFileFromCollection->commitLogs;

            [$lastHash, $previousHash] = $this->getCommitHashToCompare($commitLogsToCompare, $recentlyUpdated);

            $existInPreviousHash = collect($commitLogsToCompare)->contains($previousHash);
            $previousHashOrNot = $existInPreviousHash ? $previousHash : null;

            $isNew = false;
            if ($previousHashOrNot === null) {
                $commitPriorToLast = $previousHash
                    ? $this->git->getFileCommitLogs($dependencyFile->file, $previousHash)
                    : [];
                $isNew = count($commitPriorToLast) === 0;

                if (! $isNew) {
                    continue;
                }
            }

            $generator = $this->calculateDiffBetweenCommitsWithProgress(
                $dependencyFile->type,
                $dependencyFile->file,
                $previousHashOrNot,
                $lastHash,
                $this->skipReleaseCount,
                $isNew
            );

            $changes = collect();
            foreach ($generator as $packageChange) {
                $changes->push($packageChange);
                yield $packageChange;
            }

            // Get the return value (DependencyDiff) from the generator
            $diff = $generator->getReturn();
            if ($diff instanceof DependencyDiff) {
                $diffs->push($diff);
            }
        }

        $this->diffResult = new DiffResult($diffs, $recentlyUpdated);
    }


    /**
     * Calculate diff between commits with progress reporting
     * Yields individual PackageChange objects as they're processed
     *
     * @return \Generator<PackageChange>
     */
    private function calculateDiffBetweenCommitsWithProgress(
        PackageManagerType $type,
        string $filename,
        ?string $fromHash,
        ?string $toHash,
        bool $skipReleaseCount = false,
        bool $isNew = false
    ): \Generator {
        // Get file content for both commits
        $toContent = $toHash ? $this->git->getFileContentAtCommit(
            $filename,
            $toHash
        ) : file_get_contents(basename($filename));
        $fromContent = $fromHash ? $this->git->getFileContentAtCommit($filename, $fromHash) : null;

        if (empty($toContent)) {
            return;
        }

        // Calculate the diff
        $packageDiffs = $this->calculatePackageDiff($type, $toContent, $fromContent);

        if (empty($packageDiffs)) {
            return;
        }

        // Use short hashes for display
        $fromHashShort = $fromHash ? $this->git->getShortCommitHash($fromHash) : null;
        $toHashShort = $toHash ? $this->git->getShortCommitHash($toHash) : null;

        // Collect changes while yielding
        $changes = collect();

        foreach ($this->convertToPackageChangesWithProgress(
            $packageDiffs,
            $type,
            $skipReleaseCount
        ) as $packageChange) {
            $changes->push($packageChange);
            yield $packageChange;
        }

        // Store the complete DependencyDiff after all packages are processed
        $dependencyDiff = new DependencyDiff(
            filename: $filename,
            type: $type,
            fromCommit: $fromHashShort,
            toCommit: $toHashShort,
            changes: $changes,
            isNew: $isNew
        );

        // Return the DependencyDiff for collection
        return $dependencyDiff;
    }


    private function initializeDependencyFiles(bool $ignoreLast): void
    {
        $relativeCurrentDir = $this->git->getRelativeCurrentDir();

        // Adjust file paths relative to current directory and git root
        $this->dependencyFiles = $this->dependencyFiles->map(function (DependencyFile $dependencyFile) use (
            $relativeCurrentDir
        ) {
            if (! empty($relativeCurrentDir) && file_exists($dependencyFile->file)) {
                return $dependencyFile->withFile($relativeCurrentDir.DIRECTORY_SEPARATOR.$dependencyFile->file);
            }

            return $dependencyFile;
        });

        // Check if files have been recently updated or have commit logs
        $this->dependencyFiles = $this->dependencyFiles->map(function (DependencyFile $dependencyFile) use (
            $ignoreLast
        ) {
            $hasBeenRecentlyUpdated = ! $ignoreLast && $this->git->isFileRecentlyUpdated($dependencyFile->file);
            $commitLogs = $this->git->getFileCommitLogs($dependencyFile->file);
            $hasCommitLogs = ! empty($commitLogs);

            return $dependencyFile->withUpdatedStatus($hasBeenRecentlyUpdated, $hasCommitLogs, $commitLogs);
        });
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

    private function convertToPackageChanges(
        array $diff,
        PackageManagerType $type,
        bool $skipReleaseCount = false
    ): Collection {
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

    /**
     * Convert package diffs to PackageChange objects with progress reporting
     * Yields individual PackageChange objects as they're created, including during HTTP requests
     *
     * @return \Generator<PackageChange>
     */
    private function convertToPackageChangesWithProgress(
        array $diff,
        PackageManagerType $type,
        bool $skipReleaseCount = false
    ): \Generator {
        foreach ($diff as $package => $infos) {
            if ($infos['from'] !== null && $infos['to'] !== null) {
                if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                    $releasesCount = $skipReleaseCount ? null : $this->getReleasesCount($type, $package, $infos);
                    yield PackageChange::updated(
                        name: $package,
                        type: $type,
                        fromVersion: $infos['from'],
                        toVersion: $infos['to'],
                        releaseCount: $releasesCount,
                    );
                } else {
                    $releasesCount = $skipReleaseCount ? null : $this->getReleasesCount($type, $package, $infos);
                    yield PackageChange::downgraded(
                        name: $package,
                        type: $type,
                        fromVersion: $infos['from'],
                        toVersion: $infos['to'],
                        releaseCount: $releasesCount,
                    );
                }
            } elseif ($infos['from'] === null && $infos['to'] !== null) {
                yield PackageChange::added(
                    name: $package,
                    type: $type,
                    version: $infos['to'],
                );
            } elseif ($infos['from'] !== null && $infos['to'] === null) {
                yield PackageChange::removed(
                    name: $package,
                    type: $type,
                    version: $infos['from'],
                );
            }
        }
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
