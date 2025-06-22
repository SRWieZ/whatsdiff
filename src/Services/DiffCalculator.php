<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\Comparator;
use Symfony\Component\Console\Output\OutputInterface;

class DiffCalculator
{
    private GitRepository $git;
    private ComposerAnalyzer $composerAnalyzer;
    private NpmAnalyzer $npmAnalyzer;

    private array $dependencyFiles = [
        'composer' => [
            'file' => 'composer.lock',
            'type' => 'composer',
            'hasBeenRecentlyUpdated' => false,
            'hasCommitLogs' => false,
            'commitLogs' => [],
        ],
        'npmjs' => [
            'file' => 'package-lock.json',
            'type' => 'npmjs',
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

    public function calculateDiffs(bool $ignoreLast, OutputInterface $output): int
    {
        $this->initializeDependencyFiles($ignoreLast);

        $dependencyFiles = collect($this->dependencyFiles);

        $recentlyUpdated = $dependencyFiles->contains(fn ($file) => $file['hasBeenRecentlyUpdated'], true);
        $hasCommitLogs = $dependencyFiles->contains(fn ($file) => $file['hasCommitLogs'], true);

        // Case 1: No recent changes and no commit logs
        if (!$recentlyUpdated && !$hasCommitLogs) {
            $output->writeln('No recent changes and no commit logs found for ' .
                $dependencyFiles->pluck('file')->implode(', '));
            return 0;
        }

        if ($recentlyUpdated) {
            $output->writeln('Uncommitted changes detected on ' .
                $dependencyFiles->where('hasBeenRecentlyUpdated', true)
                    ->pluck('file')->implode(', '));
            $output->writeln('');

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
            $this->processDependencyFile($type, $filename, $recentlyUpdated, $commitLogs, $output);
        }

        return 0;
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
        array $commitLogs,
        OutputInterface $output
    ): void {
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
                $output->writeln($filename . ' has been untouched since ' . array_pop($commitPriorToLast));
                return;
            }
        }

        [$last, $previous] = $this->getFilesToCompare($filename, $lastHash, $previousHashOrNot);

        if (empty($last)) {
            return;
        }

        if ($isNew) {
            $output->writeln($filename . ($lastHash ? ' created at ' . $lastHash : ' created'));
        } else {
            $output->writeln($filename . ' between ' . $previousHash . ' and ' . ($lastHash ?? 'uncommitted changes'));
        }
        $output->writeln('');

        $diff = $this->calculatePackageDiff($type, $last, $previous);
        $this->printDiff($diff, $type, $output);
        $output->writeln('');
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

    private function printDiff(array $diff, string $type, OutputInterface $output): void
    {
        if (!count($diff)) {
            $output->writeln(' → No dependencies changes detected');
            return;
        }

        $maxStrLen = max(array_map('strlen', array_keys($diff)));
        $maxStrLenVersion = max(array_map(
            fn ($el) => strlen($el['from'] ?? ''),
            array_filter($diff, fn ($el) => $el['from'] !== null)
        ) ?: [0]);

        foreach ($diff as $package => $infos) {
            if ($infos['from'] !== null && $infos['to'] !== null) {
                if (Comparator::greaterThan($infos['to'], $infos['from'])) {
                    $releasesCount = $this->getReleasesCount($type, $package, $infos);
                    $releasesText = $releasesCount > 1 ? " ({$releasesCount} releases)" : '';

                    $output->writeln("\033[36m↑\033[0m " . str_pad($package, $maxStrLen) . ' : ' .
                        str_pad($infos['from'], $maxStrLenVersion) . ' => ' . $infos['to'] . $releasesText);
                } else {
                    $output->writeln("\033[33m↓\033[0m " . str_pad($package, $maxStrLen) . ' : ' .
                        str_pad($infos['from'], $maxStrLenVersion) . ' => ' . $infos['to']);
                }
            } elseif ($infos['from'] === null) {
                $output->writeln("\033[32m+\033[0m " . str_pad($package, $maxStrLen) . ' : ' . $infos['to']);
            } elseif ($infos['to'] === null) {
                $output->writeln("\033[31m×\033[0m " . str_pad($package, $maxStrLen) . ' : ' . $infos['from']);
            }
        }
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
