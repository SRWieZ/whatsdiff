<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as SymfonyProcess;

if (!function_exists('runCommand')) {
    function runCommand(string $command): string
    {
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
        $command = realpath(__DIR__ . '/../../bin/whatsdiff');
        $argsString = implode(' ', $args);
        
        $process = SymfonyProcess::fromShellCommandline("{$command} {$argsString}", test()->tempDir);
        $process->run();
        
        return $process->getOutput();
    }
}