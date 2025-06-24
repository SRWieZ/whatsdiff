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
        
        if (!$process->isSuccessful()) {
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
        $process->run();
        
        return $process->getOutput();
    }
}