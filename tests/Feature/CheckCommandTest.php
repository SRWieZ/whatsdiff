<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;

beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('detects package updates', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Update symfony/console
    $updatedComposerLock = generateComposerLock(['symfony/console' => 'v6.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');

    // Test checking for any change
    $process = runWhatsDiff(['check', 'symfony/console'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);

    // Test checking for update
    $process = runWhatsDiff(['check', 'symfony/console', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);

    // Test checking for downgrade (should be false)
    $process = runWhatsDiff(['check', 'symfony/console', '--is-downgraded'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false'.PHP_EOL);
});

it('detects package downgrades', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['laravel/framework' => 'v9.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Downgrade laravel/framework
    $updatedComposerLock = generateComposerLock(['laravel/framework' => 'v8.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Downgrade laravel/framework"');

    // Test checking for downgrade
    $process = runWhatsDiff(['check', 'laravel/framework', '--is-downgraded'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);

    // Test checking for update (should be false)
    $process = runWhatsDiff(['check', 'laravel/framework', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false'.PHP_EOL);
});

it('detects package additions', function () {
    // Initial composer.lock with no packages
    $initialComposerLock = generateComposerLock([]);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Add new package
    $updatedComposerLock = generateComposerLock(['monolog/monolog' => '2.8.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Add monolog/monolog"');

    // Test checking for addition
    $process = runWhatsDiff(['check', 'monolog/monolog', '--is-added'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);

    // Test checking for removal (should be false)
    $process = runWhatsDiff(['check', 'monolog/monolog', '--is-removed'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false'.PHP_EOL);
});

it('detects package removals', function () {
    // Initial composer.lock with a package
    $initialComposerLock = generateComposerLock(['guzzlehttp/guzzle' => '7.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Remove the package
    $updatedComposerLock = generateComposerLock([]);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Remove guzzlehttp/guzzle"');

    // Test checking for removal
    $process = runWhatsDiff(['check', 'guzzlehttp/guzzle', '--is-removed'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);

    // Test checking for addition (should be false)
    $process = runWhatsDiff(['check', 'guzzlehttp/guzzle', '--is-added'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false'.PHP_EOL);
});

it('returns false when package has no changes', function () {
    // Create initial composer.lock with symfony/console
    $initialComposerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Create another commit with added package but symfony/console unchanged
    $newComposerLock = generateComposerLock([
        'symfony/console' => 'v5.4.0',
        'symfony/process' => 'v5.4.0',
    ]);
    file_put_contents($this->tempDir.'/composer.lock', $newComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Added symfony/process"');

    // Add some commits without dependency changes
    file_put_contents($this->tempDir.'/README.md', '# Test Project');
    runCommand('git add README.md');
    runCommand('git commit -m "Add README"');

    // Test checking for any change - should be false since symfony/console hasn't actually changed
    $process = runWhatsDiff(['check', 'symfony/console'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false'.PHP_EOL);
});

it('supports quiet mode', function () {
    // Initial composer.lock
    $initialComposerLock = generateComposerLock(['symfony/console' => 'v5.4.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Update package
    $updatedComposerLock = generateComposerLock(['symfony/console' => 'v6.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');

    // Test quiet mode
    $process = runWhatsDiff(['check', 'symfony/console', '--quiet'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('');

    // Test quiet mode with false result
    $process = runWhatsDiff(['check', 'non-existent/package', '--quiet'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('');
});

it('works with npm packages', function () {
    // Initial package-lock.json
    $initialPackageLock = generatePackageLock(['lodash' => '4.17.15']);

    file_put_contents($this->tempDir.'/package-lock.json', $initialPackageLock);
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Initial package-lock.json"');

    // Update lodash
    $updatedPackageLock = generatePackageLock(['lodash' => '4.17.21']);

    file_put_contents($this->tempDir.'/package-lock.json', $updatedPackageLock);
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Update lodash"');

    // Test checking npm package update
    $process = runWhatsDiff(['check', 'lodash', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true'.PHP_EOL);
});

it('returns error code 2 when git repository is not found', function () {

    // Remove the .git directory to simulate a non-git repository
    cleanupTempDirectory($this->tempDir.DIRECTORY_SEPARATOR.'.git');

    $process = runWhatsDiff(
        ['check', 'symfony/console'],
    );

    expect($process->getExitCode())->toBe(Command::INVALID);
    expect($process->getOutput().$process->getErrorOutput())->toContain('Error:');

});
