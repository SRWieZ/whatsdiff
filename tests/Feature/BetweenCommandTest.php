<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Whatsdiff\Services\ProcessService;

beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('compares dependencies between two commits', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update symfony/console
    $updatedComposerLock = generateComposerLock(['symfony/console' => 'v6.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    $processService = new ProcessService();

    // Test between command with commit hashes
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', $firstCommit, $secondCommit],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('symfony/console');
    expect($process->getOutput())->toContain('v5.4.0');
    expect($process->getOutput())->toContain('v6.0.0');
});

it('compares from a commit to HEAD by default', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['laravel/framework' => 'v9.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update laravel/framework
    $updatedComposerLock = generateComposerLock(['laravel/framework' => 'v10.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update laravel/framework"');

    $processService = new ProcessService();

    // Test between command with only from commit (should compare to HEAD)
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', $firstCommit],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('laravel/framework');
    expect($process->getOutput())->toContain('v9.0.0');
    expect($process->getOutput())->toContain('v10.0.0');
});

it('supports JSON output format', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['monolog/monolog' => '2.8.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update monolog/monolog
    $updatedComposerLock = generateComposerLock(['monolog/monolog' => '3.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update monolog/monolog"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    $processService = new ProcessService();

    // Test JSON output format
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', $firstCommit, $secondCommit, '--format=json'],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    $output = json_decode($process->getOutput(), true);
    expect($output)->toBeArray();
    expect($output)->toHaveKey('diffs');
});

it('supports markdown output format', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['guzzlehttp/guzzle' => '7.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update guzzlehttp/guzzle
    $updatedComposerLock = generateComposerLock(['guzzlehttp/guzzle' => '7.5.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update guzzlehttp/guzzle"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    $processService = new ProcessService();

    // Test markdown output format
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', $firstCommit, $secondCommit, '--format=markdown'],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('##');
    expect($process->getOutput())->toContain('guzzlehttp/guzzle');
});

it('supports --no-cache option', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['doctrine/dbal' => '3.5.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update doctrine/dbal
    $updatedComposerLock = generateComposerLock(['doctrine/dbal' => '3.6.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update doctrine/dbal"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Test with --no-cache option
    $process = runWhatsDiff(['between', $firstCommit, $secondCommit, '--no-cache'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('doctrine/dbal');
});

it('works with git tags', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['phpunit/phpunit' => '9.5.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    runCommand('git tag v1.0.0');

    // Update phpunit/phpunit
    $updatedComposerLock = generateComposerLock(['phpunit/phpunit' => '10.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update phpunit/phpunit"');
    runCommand('git tag v2.0.0');

    // Test between command with git tags
    $process = runWhatsDiff(['between', 'v1.0.0', 'v2.0.0'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('phpunit/phpunit');
    expect($process->getOutput())->toContain('9.5.0');
    expect($process->getOutput())->toContain('10.0.0');
});

it('handles invalid commit references', function () {
    // Create a minimal git repository
    file_put_contents($this->tempDir.'/composer.lock', '{}');
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial commit"');

    // Test with invalid commit reference
    $process = runWhatsDiff(['between', 'invalid-commit', 'HEAD'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput().$process->getErrorOutput())->toContain('Error:');
});
