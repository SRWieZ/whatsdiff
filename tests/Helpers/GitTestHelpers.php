<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as SymfonyProcess;

if (!function_exists('runCommand')) {
    function runCommand(string $command): string
    {
        // On Windows, we need to handle command escaping differently
        if (PHP_OS_FAMILY === 'Windows') {
            // For Windows, replace single quotes with double quotes for commit messages
            $command = preg_replace("/git commit -m '([^']+)'/", 'git commit -m "$1"', $command);
        }
        
        $process = SymfonyProcess::fromShellCommandline($command, test()->tempDir);
        $process->run();
        
        // Special handling for Windows rmdir cleanup - don't fail if it's just file locking
        if (!$process->isSuccessful()) {
            if (PHP_OS_FAMILY === 'Windows' && str_contains($command, 'rmdir') && 
                str_contains($process->getErrorOutput(), 'being used by another process')) {
                // Just warn, don't fail - this is a common Windows issue
                echo "Warning: Could not clean up temp directory (file in use): " . $command . "\n";
                return $process->getOutput();
            }
            throw new \RuntimeException("Command failed: {$command}\nOutput: {$process->getOutput()}\nError: {$process->getErrorOutput()}");
        }
        
        return $process->getOutput();
    }
}

if (!function_exists('runWhatsDiff')) {
    function runWhatsDiff(array $args = []): string
    {
        $binPath = realpath(__DIR__ . '/../../bin/whatsdiff');
        $argsString = implode(' ', $args);
        
        // On Windows, run the PHP script directly
        if (PHP_OS_FAMILY === 'Windows') {
            $command = "php \"{$binPath}\" {$argsString}";
        } else {
            $command = "\"{$binPath}\" {$argsString}";
        }
        
        $process = SymfonyProcess::fromShellCommandline($command, test()->tempDir);
        // Ensure proper working directory and environment for Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $process->setEnv(['PATH' => getenv('PATH')]);
        }
        $process->run();
        
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        
        // Add debugging for Windows
        if (PHP_OS_FAMILY === 'Windows' && !$process->isSuccessful()) {
            throw new \RuntimeException(
                "whatsdiff command failed on Windows:\n" .
                "Command: {$command}\n" .
                "Working Directory: " . test()->tempDir . "\n" .
                "Exit Code: " . $process->getExitCode() . "\n" .
                "Output: {$output}\n" .
                "Error: {$errorOutput}\n" .
                "Git Status: " . runCommand('git status --porcelain') . "\n" .
                "Git Log: " . runCommand('git log --oneline -5')
            );
        }
        
        return $output;
    }
}