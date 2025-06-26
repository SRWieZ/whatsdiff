<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

function initTempDirectory(bool $initGit = true): string
{
    $tempDir = sys_get_temp_dir().'/whatsdiff-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    if ($initGit) {
        $process = new SymfonyProcess(['git', 'init'], $tempDir);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException("Failed to initialize git repository: {$process->getErrorOutput()}");
        }

        $process = new SymfonyProcess(['git', 'config', 'user.email', 'test@example.com'], $tempDir);
        $process->run();

        $process = new SymfonyProcess(['git', 'config', 'user.name', 'Test User'], $tempDir);
        $process->run();
    }

    return $tempDir;
}


function cleanupTempDirectory(string $tempDir): void
{
    if (! is_dir($tempDir)) {
        return;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, sometimes files are locked by git/processes, so try a few times
        for ($i = 0; $i < 3; $i++) {
            $process = new SymfonyProcess(['cmd', '/c', 'rmdir', '/s', '/q', $tempDir]);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            if ($i < 2) { // Not the last attempt
                usleep(500000); // Wait 0.5 seconds
            } else {
                // Last attempt failed, just warn
                echo "Warning: Could not clean up temp directory after 3 attempts: ".$tempDir."\n";
            }
        }
    } else {
        $process = new SymfonyProcess(['rm', '-rf', $tempDir]);
        $process->run();

        if (! $process->isSuccessful()) {
            echo "Warning: Could not clean up temp directory: ".$tempDir."\n";
        }
    }
}


function runCommand(string $command, ?string $cwd = null): string
{
    $workingDir = $cwd ?? test()->tempDir ?? getcwd();

    // On Windows, we need to handle command escaping differently
    if (PHP_OS_FAMILY === 'Windows') {
        // For Windows, replace single quotes with double quotes for commit messages
        $command = preg_replace("/git commit -m '([^']+)'/", 'git commit -m "$1"', $command);
    }

    // Parse command into array for Process constructor
    $commandParts = [];

    // Handle quoted strings and regular arguments
    preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $command, $matches);
    foreach ($matches[0] as $part) {
        // Remove surrounding quotes if present
        if ((str_starts_with($part, '"') && str_ends_with($part, '"')) ||
            (str_starts_with($part, "'") && str_ends_with($part, "'"))) {
            $commandParts[] = substr($part, 1, -1);
        } else {
            $commandParts[] = $part;
        }
    }

    $process = new SymfonyProcess($commandParts, $workingDir);
    $process->setTimeout(60); // 1 minute timeout
    $process->run();

    // Special handling for Windows rmdir cleanup - don't fail if it's just file locking
    if (! $process->isSuccessful()) {
        if (PHP_OS_FAMILY === 'Windows' && str_contains($command, 'rmdir') &&
            str_contains($process->getErrorOutput(), 'being used by another process')) {
            // Just warn, don't fail - this is a common Windows issue
            echo "Warning: Could not clean up temp directory (file in use): ".$command."\n";

            return $process->getOutput();
        }
        throw new \RuntimeException("Command failed: {$command}\nOutput: {$process->getOutput()}\nError: {$process->getErrorOutput()}");
    }

    return $process->getOutput();
}


function runWhatsDiff(array $args = [], ?string $cwd = null): SymfonyProcess
{
    $workingDir = $cwd ?? test()->tempDir ?? getcwd();
    $binPath = realpath(__DIR__.'/../bin/whatsdiff');

    // Find PHP executable
    $executableFinder = new ExecutableFinder();
    $phpBinary = $executableFinder->find('php');

    if (!$phpBinary) {
        throw new \RuntimeException('PHP executable not found');
    }

    // Build command array
    $command = array_merge([$phpBinary, $binPath], $args);

    // Create process
    $process = new SymfonyProcess($command, $workingDir);
    $process->setTimeout(120); // 2 minutes timeout

    // Run the process
    $process->run();

    return $process;
}
