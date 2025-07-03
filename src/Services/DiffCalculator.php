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
    private array $dependencyTypes;

    // Fluent interface state
    private ?string $fromCommit = null;
    private ?string $toCommit = null;
    private bool $ignoreLast = false;
    private bool $skipReleaseCount = false;
    private ?DiffResult $diffResult = null;

    public function __construct(
        GitRepository $git,
        ComposerAnalyzer $composerAnalyzer,
        NpmAnalyzer $npmAnalyzer,
        private readonly SemverAnalyzer $semverAnalyzer
    ) {
        $this->git = $git;
        $this->composerAnalyzer = $composerAnalyzer;
        $this->npmAnalyzer = $npmAnalyzer;
        $this->dependencyFiles = collect();
        $this->dependencyTypes = [];
    }

    private function initializeDependencyFilesStructure(): void
    {
        if ($this->dependencyFiles->isNotEmpty()) {
            return;
        }

        foreach ($this->getActiveDependencyTypes() as $type) {
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
     * Set a specific package manager type to include
     */
    public function for(PackageManagerType $type): self
    {
        $this->dependencyTypes[] = $type;

        return $this;
    }

    /**
     * Get the active dependency types (filtered or all if none specified)
     */
    private function getActiveDependencyTypes(): array
    {
        return empty($this->dependencyTypes) ? PackageManagerType::cases() : $this->dependencyTypes;
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

        [, $generator] = $this->runWithProgress();

        foreach ($generator as $packageChange) {
            // Just iterate through the generator to trigger the diff calculation
        }

        // Ensure we always return a valid DiffResult, even if something went wrong
        return $this->getResult();
    }

    /**
     * Get the result of the last run() call
     */
    public function getResult(): DiffResult
    {
        return $this->diffResult ?? new DiffResult(collect(), false);
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
            return $this->countPackageChangesForCustomCommits();
        }

        // Handle normal flow
        $this->initializeDependencyFiles($this->ignoreLast);

        $relevantFiles = $this->getRelevantFiles();
        if ($relevantFiles->isEmpty()) {
            return 0;
        }

        return $this->countPackageChangesForFiles($relevantFiles);
    }

    /**
     * Process diffs with progress reporting
     *
     * @return \Generator<PackageChange>
     */
    private function processDiffsWithProgress(): \Generator
    {
        $diffs = collect();

        // Initialize with empty result to ensure we always have a valid DiffResult
        $this->diffResult = new DiffResult($diffs, false);

        // Handle custom commits case
        if ($this->fromCommit !== null || $this->toCommit !== null) {
            yield from $this->processCustomCommitsWithProgress($diffs);
            $this->diffResult = new DiffResult($diffs, false);

            return;
        }

        // Handle normal flow
        $this->initializeDependencyFiles($this->ignoreLast);

        $relevantFiles = $this->getRelevantFiles();

        // Case 1: No recent changes and no commit logs
        if ($relevantFiles->isEmpty()) {
            $this->diffResult = new DiffResult($diffs, false);

            return;
        }

        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
        yield from $this->processFilesWithProgress($relevantFiles, $diffs, $recentlyUpdated);
        $this->diffResult = new DiffResult($diffs, $recentlyUpdated);
    }


    /**
     * Calculate diff between commits with progress reporting
     * Yields individual PackageChange objects as they're processed
     *
     * @return \Generator<PackageChange>
     * @noinspection PhpInconsistentReturnPointsInspection
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
        $toContent = $this->getFileContents($filename, $toHash);
        $fromContent = $this->getFileContents($filename, $fromHash);

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
        // Initialize dependency files structure if not already done
        $this->initializeDependencyFilesStructure();

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
            $packageChange = $this->createPackageChange($package, $infos, $type, $skipReleaseCount);
            if ($packageChange !== null) {
                yield $packageChange;
            }
        }
    }

    private function getReleasesCount(PackageManagerType $type, string $package, array $infos): ?int
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

    /**
     * Resolve commit hashes from commit references
     *
     * @return array{?string, string} [fromHash, toHash]
     */
    private function resolveCommitHashes(): array
    {
        $fromHash = $this->fromCommit ? $this->git->resolveCommitHash($this->fromCommit) : null;
        $toHash = $this->toCommit ? $this->git->resolveCommitHash($this->toCommit) : $this->git->resolveCommitHash('HEAD');

        return [$fromHash, $toHash];
    }

    /**
     * Get file contents from a specific commit or current working directory
     */
    private function getFileContents(string $filename, ?string $hash): ?string
    {
        if ($hash) {
            return $this->git->getFileContentAtCommit($filename, $hash);
        }

        $basename = basename($filename);

        return file_exists($basename) ? file_get_contents($basename) : null;
    }

    /**
     * Get relevant dependency files that have changes
     */
    private function getRelevantFiles(): Collection
    {
        $this->initializeDependencyFilesStructure();

        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
        $hasCommitLogs = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasCommitLogs);

        if (! $recentlyUpdated && ! $hasCommitLogs) {
            return collect();
        }

        return $recentlyUpdated
            ? $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated)
            : $this->dependencyFiles->filter(fn (DependencyFile $file) => $file->hasCommitLogs);
    }

    /**
     * Check if a file is new (didn't exist in the previous commit)
     */
    private function isFileNew(string $filename, ?string $previousHash): bool
    {
        if ($previousHash === null) {
            return true;
        }

        $commitPriorToLast = $this->git->getFileCommitLogs($filename, $previousHash);

        return count($commitPriorToLast) === 0;
    }

    /**
     * Create a PackageChange object based on version differences
     */
    private function createPackageChange(
        string $package,
        array $infos,
        PackageManagerType $type,
        bool $skipReleaseCount
    ): ?PackageChange {
        // Both versions exist - either updated or downgraded
        if ($infos['from'] !== null && $infos['to'] !== null) {
            $releasesCount = $skipReleaseCount ? null : $this->getReleasesCount($type, $package, $infos);
            $semverChangeType = $this->semverAnalyzer->determineSemverChangeType($infos['from'], $infos['to']);

            if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                return PackageChange::updated(
                    name: $package,
                    type: $type,
                    fromVersion: $infos['from'],
                    toVersion: $infos['to'],
                    releaseCount: $releasesCount,
                    semver: $semverChangeType,
                );
            } else {
                return PackageChange::downgraded(
                    name: $package,
                    type: $type,
                    fromVersion: $infos['from'],
                    toVersion: $infos['to'],
                    releaseCount: $releasesCount,
                    semver: $semverChangeType,
                );
            }
        }

        // Package was added
        if ($infos['from'] === null && $infos['to'] !== null) {
            return PackageChange::added(
                name: $package,
                type: $type,
                version: $infos['to'],
            );
        }

        // Package was removed
        if ($infos['from'] !== null && $infos['to'] === null) {
            return PackageChange::removed(
                name: $package,
                type: $type,
                version: $infos['from'],
            );
        }

        return null;
    }

    /**
     * Count package changes for custom commits
     */
    private function countPackageChangesForCustomCommits(): int
    {
        $totalCount = 0;
        [$fromHash, $toHash] = $this->resolveCommitHashes();

        foreach ($this->getActiveDependencyTypes() as $type) {
            $filename = $type->getLockFileName();
            $toContent = $this->getFileContents($filename, $toHash);
            $fromContent = $this->getFileContents($filename, $fromHash);

            if (! empty($toContent)) {
                $packageDiffs = $this->calculatePackageDiff($type, $toContent, $fromContent);
                $totalCount += count($packageDiffs);
            }
        }

        return $totalCount;
    }

    /**
     * Count package changes for relevant files
     */
    private function countPackageChangesForFiles(Collection $relevantFiles): int
    {
        $totalCount = 0;
        $recentlyUpdated = $this->dependencyFiles->contains(fn (DependencyFile $file) => $file->hasBeenRecentlyUpdated);
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
                $isNew = $this->isFileNew($dependencyFile->file, $previousHash);
                if (! $isNew) {
                    continue;
                }
            }

            // Get file contents and calculate diff
            $toContent = $this->getFileContents($dependencyFile->file, $lastHash);
            $fromContent = $this->getFileContents($dependencyFile->file, $previousHashOrNot);

            if (! empty($toContent)) {
                $packageDiffs = $this->calculatePackageDiff($dependencyFile->type, $toContent, $fromContent);
                $totalCount += count($packageDiffs);
            }
        }

        return $totalCount;
    }

    /**
     * Process custom commits with progress reporting
     *
     * @return \Generator<PackageChange>
     */
    private function processCustomCommitsWithProgress(Collection $diffs): \Generator
    {
        [$fromHash, $toHash] = $this->resolveCommitHashes();

        foreach ($this->getActiveDependencyTypes() as $type) {
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
    }

    /**
     * Process files with progress reporting
     *
     * @return \Generator<PackageChange>
     */
    private function processFilesWithProgress(
        Collection $relevantFiles,
        Collection $diffs,
        bool $recentlyUpdated
    ): \Generator {
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
                $isNew = $this->isFileNew($dependencyFile->file, $previousHash);
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
    }
}
