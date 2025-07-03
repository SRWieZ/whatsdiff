<?php


beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

test('application boots successfully', function () {
    $process = runWhatsDiff(['--version']);

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('whatsdiff');
    expect($process->getOutput())->toContain('PHP version:');
});

test('help command works', function () {
    $process = runWhatsDiff(['--help']);

    expect($process->getExitCode())->toBe(0);
    expect($process->getOutput())->toContain('See what\'s changed in your project\'s dependencies');
    expect($process->getOutput())->toContain('--ignore-last');
});

test('list command works', function () {
    $process = runWhatsDiff(['list']);

    expect($process->getExitCode())->toBe(0);
    $outputString = $process->getOutput() . $process->getErrorOutput();

    // Should contain some package names or "No recent changes"
    expect($outputString)->toContain('check')
        ->and($outputString)->toContain('diff')
        ->and($outputString)->toContain('list')
        ->and($outputString)->toContain('help');
});

test('main command executes without errors', function () {
    // Test without --ignore-last to avoid git history dependencies in CI
    $process = runWhatsDiff();

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
    $process = runWhatsDiff(['--help']);

    expect($process->getExitCode())->toBe(0);
    $outputString = $process->getOutput();
    // Symfony Console specific output patterns
    expect($outputString)->toContain('Usage:');
    expect($outputString)->toContain('Options:');
    expect($outputString)->toContain('Help:');
});

test('error handling works for invalid options', function () {
    $process = runWhatsDiff(['--invalid-option']);

    expect($process->getExitCode())->not->toBe(0);
    $outputString = $process->getOutput() . $process->getErrorOutput();
    expect($outputString)->toContain('The "--invalid-option" option does not exist');
});

test('ignore-last option is properly recognized', function () {
    // This should not throw an error about unknown option
    $process = runWhatsDiff(['--ignore-last', '--help']);

    expect($process->getExitCode())->toBe(0);
    $outputString = $process->getOutput() . $process->getErrorOutput();
    expect($outputString)->toContain('--ignore-last');
    expect($outputString)->toContain('Ignore last uncommitted changes');
});
