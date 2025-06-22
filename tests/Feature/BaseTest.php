<?php

use Whatsdiff\Services\ProcessService;

test('application boots successfully', function () {
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff', '--version']);

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('whatsdiff');
    expect($process->getOutput())->toContain('PHP version:');
});

test('help command works', function () {
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff', '--help']);

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('See what\'s changed in your project\'s dependencies');
    expect($process->getOutput())->toContain('--ignore-last');
});

test('main command executes without errors', function () {
    // Test without --ignore-last to avoid git history dependencies in CI
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff']);

    $outputString = $process->getOutput() . $process->getErrorOutput();

    // Should produce some meaningful output
    expect(strlen($outputString))->toBeGreaterThan(0);

    // Should not contain PHP fatal errors
    expect($outputString)->not->toContain('Fatal error');
    expect($outputString)->not->toContain('PHP Fatal error');

    // If exit code is not 0, it should be a graceful error (not a crash)
    if ($process->getExitCode() !== 0) {
        // Should contain an error message, not a crash
        expect($outputString)->toMatch('/(Error:|not in a git repository|git command failed)/i');
    } else {
        // If successful, should contain expected output
        expect($outputString)->toMatch('/(composer\.lock|package-lock\.json|No recent changes)/');
    }
});

test('symfony console integration works', function () {
    // Test that the application is using Symfony Console by checking help output structure
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff', '--help']);

    expect($process->getExitCode())->toBe(0);
    $outputString = $process->getOutput();
    // Symfony Console specific output patterns
    expect($outputString)->toContain('Usage:');
    expect($outputString)->toContain('Options:');
    expect($outputString)->toContain('Help:');
});

test('error handling works for invalid options', function () {
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff', '--invalid-option']);

    expect($process->getExitCode())->not->toBe(0);
    $outputString = $process->getOutput() . $process->getErrorOutput();
    expect($outputString)->toContain('The "--invalid-option" option does not exist');
});

test('ignore-last option is properly recognized', function () {
    // This should not throw an error about unknown option
    $processService = new ProcessService();
    $process = $processService->php(['bin/whatsdiff', '--ignore-last', '--help']);

    expect($process->getExitCode())->toBe(0);
    $outputString = $process->getOutput() . $process->getErrorOutput();
    expect($outputString)->toContain('--ignore-last');
    expect($outputString)->toContain('Ignore last uncommitted changes');
});
