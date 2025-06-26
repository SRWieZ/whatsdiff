<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as SymfonyProcess;


function initTempDirectory(bool $initGit = true): string
{
    $tempDir = sys_get_temp_dir().'/whatsdiff-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    if ($initGit) {
        $process = SymfonyProcess::fromShellCommandline('git init', $tempDir);
        $process->run();

        if ( ! $process->isSuccessful()) {
            throw new \RuntimeException("Failed to initialize git repository: {$process->getErrorOutput()}");
        }

        $process = SymfonyProcess::fromShellCommandline('git config user.email "test@example.com"', $tempDir);
        $process->run();

        $process = SymfonyProcess::fromShellCommandline('git config user.name "Test User"', $tempDir);
        $process->run();
    }

    return $tempDir;
}


function cleanupTempDirectory(string $tempDir): void
{
    if ( ! is_dir($tempDir)) {
        return;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, sometimes files are locked by git/processes, so try a few times
        for ($i = 0; $i < 3; $i++) {
            $process = SymfonyProcess::fromShellCommandline("rmdir /s /q \"{$tempDir}\"");
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
        $process = SymfonyProcess::fromShellCommandline("rm -rf \"{$tempDir}\"");
        $process->run();

        if ( ! $process->isSuccessful()) {
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

    $process = SymfonyProcess::fromShellCommandline($command, $workingDir);
    $process->run();

    // Special handling for Windows rmdir cleanup - don't fail if it's just file locking
    if ( ! $process->isSuccessful()) {
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


function runWhatsDiff(array $args = [], ?string $cwd = null): string
{
    $workingDir = $cwd ?? test()->tempDir ?? getcwd();
    $binPath = realpath(__DIR__.'/../bin/whatsdiff');
    $argsString = implode(' ', $args);

    // On Windows, run the PHP script directly
    if (PHP_OS_FAMILY === 'Windows') {
        $command = "php \"{$binPath}\" {$argsString}";
    } else {
        $command = "\"{$binPath}\" {$argsString}";
    }

    $process = SymfonyProcess::fromShellCommandline($command, $workingDir);

    // Ensure proper working directory and environment for Windows
    if (PHP_OS_FAMILY === 'Windows') {
        $process->setEnv(['PATH' => getenv('PATH')]);
    }

    // Run the process
    $process->run();

    $output = $process->getOutput();
    $errorOutput = $process->getErrorOutput();

    // Add debugging for Windows
    if (PHP_OS_FAMILY === 'Windows' && ! $process->isSuccessful()) {
        throw new \RuntimeException(
            "whatsdiff command failed on Windows:\n".
            "Command: {$command}\n".
            "Working Directory: ".$workingDir."\n".
            "Exit Code: ".$process->getExitCode()."\n".
            "Output: {$output}\n".
            "Error: {$errorOutput}\n".
            "Git Status: ".runCommand('git status --porcelain', $workingDir)."\n".
            "Git Log: ".runCommand('git log --oneline -5', $workingDir)
        );
    }

    return $output;
}

