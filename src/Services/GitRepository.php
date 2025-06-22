<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

class GitRepository
{
    private string $gitRoot;
    private string $currentDir;
    private string $relativeCurrentDir;

    public function __construct()
    {
        $this->gitRoot = rtrim(trim(shell_exec('git rev-parse --show-toplevel') ?? ''), DIRECTORY_SEPARATOR);
        $this->currentDir = rtrim(getcwd() ?: '', DIRECTORY_SEPARATOR);
        $this->relativeCurrentDir = ltrim(str_replace($this->gitRoot, '', $this->currentDir), DIRECTORY_SEPARATOR);
    }

    public function getGitRoot(): string
    {
        return $this->gitRoot;
    }

    public function getCurrentDir(): string
    {
        return $this->currentDir;
    }

    public function getRelativeCurrentDir(): string
    {
        return $this->relativeCurrentDir;
    }

    public function getFileCommitLogs(string $filename, string $beforeHash = ''): array
    {
        $filename = escapeshellarg($filename);
        $beforeHash = $beforeHash ? escapeshellarg($beforeHash) : '';

        $cmd = "git log {$beforeHash} --pretty=format:'%h' -- {$filename}";

        $output = $this->shellExecInGitRoot($cmd);

        if (is_null($output) || empty(trim($output))) {
            return [];
        }

        return explode("\n", trim($output));
    }

    public function getMultipleFilesCommitLogs(array $filenames): array
    {
        $escapedFilenames = array_map('escapeshellarg', $filenames);
        $filesString = implode(' ', $escapedFilenames);

        $output = $this->shellExecInGitRoot("git log --pretty=format:'%h' -- {$filesString}");

        if (is_null($output) || empty(trim($output))) {
            return [];
        }

        return explode("\n", trim($output));
    }

    public function isFileRecentlyUpdated(string $filename): bool
    {
        $output = shell_exec('git status --porcelain');

        if (empty($output)) {
            return false;
        }

        $status = collect(explode("\n", trim($output)))
            ->filter()
            ->mapWithKeys(function ($line) {
                $parts = array_values(array_filter(explode(' ', $line)));
                return isset($parts[1]) ? [$parts[1] => $parts[0]] : [];
            });

        // If the file exists and is not in the list of untracked files
        if (!empty($this->relativeCurrentDir) && file_exists($filename) && !$status->has($filename)) {
            return true; // Created
        }

        return in_array($status->get($filename), [
            'AM', // Added and modified
            'M',  // Modified
            'A',  // Added
            '??', // Untracked
        ]);
    }

    public function getFileContentAtCommit(string $filename, string $commitHash): string
    {
        $filename = escapeshellarg($filename);
        $commitHash = escapeshellarg($commitHash);

        return shell_exec("git show {$commitHash}:{$filename}") ?? '';
    }

    private function shellExecInGitRoot(string $cmd): ?string
    {
        $oldCwd = getcwd();
        chdir($this->gitRoot);
        $output = shell_exec($cmd);
        chdir($oldCwd ?: '');

        return $output;
    }
}
