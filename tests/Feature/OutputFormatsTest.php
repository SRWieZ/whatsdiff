<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Whatsdiff\Analyzers\PackageManagerType;

beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

test('text format outputs readable diff information', function () {
    // Setup git repository with dependency changes
    $initialComposerLock = generateComposerLock(['laravel/framework' => 'v9.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Update package
    $updatedComposerLock = generateComposerLock(['laravel/framework' => 'v10.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update laravel/framework"', $this->tempDir);

    // Test default text format
    $process = runWhatsDiff([], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    $output = $process->getOutput();

    // Should contain package information in human-readable format
    expect($output)->toContain('laravel/framework')
        ->and($output)->toContain('v9.0.0')
        ->and($output)->toContain('v10.0.0')
        ->and($output)->toContain('composer.lock');
});

test('json format always returns valid JSON ', function () {
    // Setup git repository with dependency changes
    $initialComposerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Update package
    $updatedComposerLock = generateComposerLock(['symfony/console' => 'v6.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update symfony/console"', $this->tempDir);

    // Test JSON format
    $process = runWhatsDiff(['--format=json'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);

    // Verify valid JSON
    $output = $process->getOutput();
    $json = json_decode($output, true);
    $jsonError = json_last_error();

    expect($jsonError)->toBe(JSON_ERROR_NONE)
        ->and($json)->toBeArray()
        ->and($json)->toHaveKey('diffs')
        ->and($json['diffs'])->toBeArray();

    // Check structure contains expected data
    $composerDiff = $json['diffs'][0];
    foreach ($json['diffs'] as $diff) {
        if ($diff['type'] === PackageManagerType::COMPOSER) {
            $composerDiff = $diff;
            break;
        }
    }

    expect($composerDiff)->not->toBeNull()
        ->and($composerDiff)->toHaveKey('changes')
        ->and($composerDiff['changes'])->toBeArray();
});

test('json format returns valid JSON error on failure', function () {
    // Test in a non-git directory
    $nonGitDir = sys_get_temp_dir().'/whatsdiff-non-git-'.uniqid();
    mkdir($nonGitDir, 0755, true);

    try {
        $process = runWhatsDiff(['--format=json'], $nonGitDir);

        // Should fail but still return valid JSON
        expect($process->getExitCode())->not->toBe(Command::SUCCESS);

        $output = $process->getOutput();
        $json = json_decode($output, true);
        $jsonError = json_last_error();

        expect($jsonError)->toBe(JSON_ERROR_NONE)
            ->and($json)->toBeArray()
            ->and($json)->toHaveKey('error');

    } finally {
        rmdir($nonGitDir);
    }
});

test('markdown format outputs proper markdown structure', function () {
    // Setup git repository with dependency changes
    $initialComposerLock = generateComposerLock(['guzzlehttp/guzzle' => '7.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Add a package
    $updatedComposerLock = generateComposerLock([
        'guzzlehttp/guzzle' => '7.4.0',
        'monolog/monolog' => '3.0.0',
    ]);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Add monolog/monolog"', $this->tempDir);

    // Test markdown format
    $process = runWhatsDiff(['--format=markdown'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    $output = $process->getOutput();

    // Should contain markdown headers and structure
    expect($output)->toContain('##')
        ->and($output)->toContain('composer.lock')
        ->and($output)->toContain('monolog/monolog')
        ->and($output)->toContain('### Added');
});

test('text format handles npm packages correctly', function () {
    // Setup git repository with npm package changes
    $initialPackageLock = generatePackageLock(['lodash' => '4.17.20']);

    file_put_contents($this->tempDir.'/package-lock.json', $initialPackageLock);
    runCommand('git add package-lock.json', $this->tempDir);
    runCommand('git commit -m "Initial package-lock.json"', $this->tempDir);

    // Update package
    $updatedPackageLock = generatePackageLock(['lodash' => '4.17.21']);

    file_put_contents($this->tempDir.'/package-lock.json', $updatedPackageLock);
    runCommand('git add package-lock.json', $this->tempDir);
    runCommand('git commit -m "Update lodash"', $this->tempDir);

    // Test text format with npm
    $process = runWhatsDiff([], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    $output = $process->getOutput();

    // Should contain npm package information
    expect($output)->toContain('lodash')
        ->and($output)->toContain('4.17.20')
        ->and($output)->toContain('4.17.21')
        ->and($output)->toContain('package-lock.json');
});

test('all formats handle empty diff correctly', function () {
    // Setup git repository without any dependency file changes
    file_put_contents($this->tempDir.'/README.md', '# Test Project');
    runCommand('git add README.md', $this->tempDir);
    runCommand('git commit -m "Initial commit"', $this->tempDir);

    // Test text format
    $process = runWhatsDiff([], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('No recent changes');

    // Test JSON format
    $process = runWhatsDiff(['--format=json'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    $json = json_decode($process->getOutput(), true);
    expect(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($json)->toHaveKey('diffs')
        ->and($json['diffs'])->toBeEmpty();

    // Test markdown format
    $process = runWhatsDiff(['--format=markdown'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('No dependency changes detected');
});
