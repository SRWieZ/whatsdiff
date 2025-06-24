<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

class GitRepository
{
    private string $gitRoot;
    private string $currentDir;
    private string $relativeCurrentDir;
    private ProcessService $processService;

    public function __construct(?ProcessService $processService = null)
    {
        $this->processService = $processService ?? new ProcessService();

        $process = $this->processService->git(['rev-parse', '--show-toplevel']);

        if (!$process->isSuccessful() || empty(trim($process->getOutput()))) {
            throw new \RuntimeException('Not in a git repository or git command failed');
        }

        $this->gitRoot = rtrim(trim($process->getOutput()), DIRECTORY_SEPARATOR);
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
        $args = ['log', '--pretty=format:%h', '--', $filename];

        if ($beforeHash) {
            array_splice($args, 1, 0, $beforeHash);
        }

        $process = $this->processService->git($args, $this->gitRoot);

        // Debug output for Windows
        if (PHP_OS_FAMILY === 'Windows') {
            echo "GitRepository::getFileCommitLogs DEBUG:\n";
            echo "  filename: {$filename}\n";
            echo "  gitRoot: {$this->gitRoot}\n";
            echo "  currentDir: {$this->currentDir}\n";
            echo "  relativeCurrentDir: {$this->relativeCurrentDir}\n";
            echo "  command: " . implode(' ', $args) . "\n";
            echo "  process successful: " . ($process->isSuccessful() ? 'YES' : 'NO') . "\n";
            echo "  process output: '" . trim($process->getOutput()) . "'\n";
            echo "  process error: '" . trim($process->getErrorOutput()) . "'\n";
        }

        if (!$process->isSuccessful() || empty(trim($process->getOutput()))) {
            return [];
        }

        return explode("\n", trim($process->getOutput()));
    }

    public function getMultipleFilesCommitLogs(array $filenames): array
    {
        $args = array_merge(['log', '--pretty=format:%h', '--'], $filenames);

        $process = $this->processService->git($args, $this->gitRoot);

        if (!$process->isSuccessful() || empty(trim($process->getOutput()))) {
            return [];
        }

        return explode("\n", trim($process->getOutput()));
    }

    public function isFileRecentlyUpdated(string $filename): bool
    {
        $process = $this->processService->git(['status', '--porcelain'], $this->gitRoot);

        if (!$process->isSuccessful() || empty(trim($process->getOutput()))) {
            return false;
        }

        $lines = explode("\n", trim($process->getOutput()));
        $status = collect($lines)
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
        $process = $this->processService->git(
            ['show', $commitHash . ':' . $filename],
            $this->gitRoot
        );

        return $process->isSuccessful() ? $process->getOutput() : '';
    }
}
