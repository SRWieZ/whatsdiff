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
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'symfony/console',
                'version' => 'v5.4.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/symfony/console.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update symfony/console
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
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
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'laravel/framework',
                'version' => 'v9.0.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update laravel/framework
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v10.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
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
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'monolog/monolog',
                'version' => '2.8.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/Seldaek/monolog.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update monolog/monolog
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = '3.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
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
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'guzzlehttp/guzzle',
                'version' => '7.4.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/guzzle/guzzle.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update guzzlehttp/guzzle
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = '7.5.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
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
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'doctrine/dbal',
                'version' => '3.5.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/doctrine/dbal.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update doctrine/dbal
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = '3.6.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update doctrine/dbal"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    $processService = new ProcessService();

    // Test with --no-cache option
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', $firstCommit, $secondCommit, '--no-cache'],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toContain('doctrine/dbal');
});

it('works with git tags', function () {
    // Initial composer.lock
    $initialComposerLock = [
        '_readme'      => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages'     => [
            [
                'name'    => 'phpunit/phpunit',
                'version' => '9.5.0',
                'source'  => ['type' => 'git', 'url' => 'https://github.com/sebastianbergmann/phpunit.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    runCommand('git tag v1.0.0');

    // Update phpunit/phpunit
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = '10.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update phpunit/phpunit"');
    runCommand('git tag v2.0.0');

    $processService = new ProcessService();

    // Test between command with git tags
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', 'v1.0.0', 'v2.0.0'],
        $this->tempDir
    );

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

    $processService = new ProcessService();

    // Test with invalid commit reference
    $process = $processService->php(
        [realpath(__DIR__.'/../../bin/whatsdiff'), 'between', 'invalid-commit', 'HEAD'],
        $this->tempDir
    );

    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput().$process->getErrorOutput())->toContain('Error:');
});
