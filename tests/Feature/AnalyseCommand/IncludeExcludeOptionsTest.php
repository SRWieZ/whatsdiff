<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;

beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('includes only composer dependencies with --include=composer', function () {
    // Create both composer.lock and package-lock.json
    $composerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);
    $packageLock = generatePackageLock(['lodash' => '4.17.20']);

    // Create initial commits
    file_put_contents($this->tempDir . '/composer.lock', $composerLock);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLock);
    runCommand('git add .');
    runCommand('git commit -m "Initial dependencies"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update both files
    $composerLock = generateComposerLock(['symfony/console' => 'v6.0.0']);
    $packageLock = generatePackageLock(['lodash' => '4.17.21']);

    file_put_contents($this->tempDir . '/composer.lock', $composerLock);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLock);
    runCommand('git add .');
    runCommand('git commit -m "Update dependencies"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Test with --include=composer
    $process = runWhatsDiff(['analyse', '--from=' . $firstCommit, '--to=' . $secondCommit, '--include=composer'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('symfony/console'); // Should include Composer package
    expect($process->getOutput())->not->toContain('lodash'); // Should not include npm package
});

it('includes only npm dependencies with --include=npmjs', function () {
    // Create both composer.lock and package-lock.json
    $composerLock = generateComposerLock(['laravel/framework' => 'v9.0.0']);
    $packageLock = generatePackageLock(['react' => '17.0.0']);

    // Create initial commits
    file_put_contents($this->tempDir . '/composer.lock', $composerLock);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLock);
    runCommand('git add .');
    runCommand('git commit -m "Initial dependencies"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update both files
    $composerLockUpdated = generateComposerLock(['laravel/framework' => 'v10.0.0']);
    $packageLockUpdated = generatePackageLock(['react' => '18.0.0']);

    file_put_contents($this->tempDir . '/composer.lock', $composerLockUpdated);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLockUpdated);
    runCommand('git add .');
    runCommand('git commit -m "Update dependencies"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Test with --include=npmjs
    $process = runWhatsDiff(['analyse', '--from=' . $firstCommit, '--to=' . $secondCommit, '--include=npmjs'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('react'); // Should include npm package
    expect($process->getOutput())->not->toContain('laravel/framework'); // Should not include Composer package
});

it('excludes composer dependencies with --exclude=composer', function () {
    // Create both composer.lock and package-lock.json
    $composerLock = generateComposerLock(['monolog/monolog' => '2.8.0']);
    $packageLock = generatePackageLock(['axios' => '0.27.0']);

    // Create initial commits
    file_put_contents($this->tempDir . '/composer.lock', $composerLock);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLock);
    runCommand('git add .');
    runCommand('git commit -m "Initial dependencies"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update both files
    $composerLockUpdated = generateComposerLock(['monolog/monolog' => '3.0.0']);
    $packageLockUpdated = generatePackageLock(['axios' => '1.0.0']);

    file_put_contents($this->tempDir . '/composer.lock', $composerLockUpdated);
    file_put_contents($this->tempDir . '/package-lock.json', $packageLockUpdated);
    runCommand('git add .');
    runCommand('git commit -m "Update dependencies"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Test with --exclude=composer
    $process = runWhatsDiff(['analyse', '--from=' . $firstCommit, '--to=' . $secondCommit, '--exclude=composer'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('axios'); // Should include npm package
    expect($process->getOutput())->not->toContain('monolog/monolog'); // Should not include Composer package
});

it('supports npm alias with --include=npm', function () {
    // Create package-lock.json only
    $packageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => [
                'name' => 'test-project',
                'version' => '1.0.0',
            ],
            'node_modules/vue' => [
                'version' => '3.2.0',
                'resolved' => 'https://registry.npmjs.org/vue/-/vue-3.2.0.tgz',
            ],
        ],
    ];

    // Create initial commit
    file_put_contents($this->tempDir . '/package-lock.json', json_encode($packageLock, JSON_PRETTY_PRINT));
    runCommand('git add .');
    runCommand('git commit -m "Initial npm dependencies"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update npm package
    $packageLock['packages']['node_modules/vue']['version'] = '3.3.0';

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($packageLock, JSON_PRETTY_PRINT));
    runCommand('git add .');
    runCommand('git commit -m "Update vue"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Test with --include=npm (alias for npmjs)
    $process = runWhatsDiff(['analyse', '--from=' . $firstCommit, '--to=' . $secondCommit, '--include=npm'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('vue'); // Should include npm package
});

it('fails when both --include and --exclude are provided', function () {
    // Create minimal git repository
    file_put_contents($this->tempDir . '/composer.lock', '{}');
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial commit"');

    // Test with both --include and --exclude (should fail)
    $process = runWhatsDiff(['analyse', '--include=composer', '--exclude=npmjs'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput() . $process->getErrorOutput())->toContain('Cannot use both --include and --exclude options');
});

it('fails with invalid package manager type', function () {
    // Create minimal git repository
    file_put_contents($this->tempDir . '/composer.lock', '{}');
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial commit"');

    // Test with invalid package manager type
    $process = runWhatsDiff(['analyse', '--include=invalid'], $this->tempDir);

    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput() . $process->getErrorOutput())->toContain('Invalid package manager type');
    expect($process->getOutput() . $process->getErrorOutput())->toContain('Valid types: composer, npmjs');
});
