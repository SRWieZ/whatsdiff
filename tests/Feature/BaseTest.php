<?php

test('application boots successfully', function () {
    exec('php bin/whatsdiff --version', $output, $exitCode);

    expect($exitCode)->toBe(0);
    expect(implode("\n", $output))->toContain('whatsdiff');
    expect(implode("\n", $output))->toContain('PHP version:');
});

test('help command works', function () {
    exec('php bin/whatsdiff --help', $output, $exitCode);

    expect($exitCode)->toBe(0);
    expect(implode("\n", $output))->toContain('See what\'s changed in your project\'s dependencies');
    expect(implode("\n", $output))->toContain('--ignore-last');
});

test('main command executes without errors', function () {
    // Test without --ignore-last to avoid git history dependencies in CI
    exec('php bin/whatsdiff 2>&1', $output, $exitCode);

    $outputString = implode("\n", $output);

    // Should produce some meaningful output
    expect(strlen($outputString))->toBeGreaterThan(0);

    // Should not contain PHP fatal errors
    expect($outputString)->not->toContain('Fatal error');
    expect($outputString)->not->toContain('PHP Fatal error');

    // If exit code is not 0, it should be a graceful error (not a crash)
    if ($exitCode !== 0) {
        // Should contain an error message, not a crash
        expect($outputString)->toMatch('/(Error:|not in a git repository|git command failed)/i');
    } else {
        // If successful, should contain expected output
        expect($outputString)->toMatch('/(composer\.lock|package-lock\.json|No recent changes)/');
    }
});

test('symfony console integration works', function () {
    // Test that the application is using Symfony Console by checking help output structure
    exec('php bin/whatsdiff --help', $output, $exitCode);

    expect($exitCode)->toBe(0);
    $outputString = implode("\n", $output);
    // Symfony Console specific output patterns
    expect($outputString)->toContain('Usage:');
    expect($outputString)->toContain('Options:');
    expect($outputString)->toContain('Help:');
});

test('error handling works for invalid options', function () {
    exec('php bin/whatsdiff --invalid-option 2>&1', $output, $exitCode);

    expect($exitCode)->not->toBe(0);
    expect(implode("\n", $output))->toContain('The "--invalid-option" option does not exist');
});

test('ignore-last option is properly recognized', function () {
    // This should not throw an error about unknown option
    exec('php bin/whatsdiff --ignore-last --help 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0);
    expect(implode("\n", $output))->toContain('--ignore-last');
    expect(implode("\n", $output))->toContain('Ignore last uncommitted changes');
});
