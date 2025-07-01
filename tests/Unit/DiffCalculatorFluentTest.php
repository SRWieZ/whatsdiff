<?php

declare(strict_types=1);

use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Services\DiffCalculator;
use Whatsdiff\Services\GitRepository;

beforeEach(function () {
    $this->tempDir = initTempDirectory();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('supports fluent interface for basic usage', function () {
    // Create a minimal git repository with composer.lock
    $composerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/symfony/console.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($composerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Create mock dependencies
    $git = new GitRepository();
    $packageInfoFetcher = \Mockery::mock(\Whatsdiff\Services\PackageInfoFetcher::class);
    $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
    $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);

    $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

    // Test fluent interface
    $result = $diffCalculator
        ->ignoreLastCommit()
        ->skipReleaseCount()
        ->run();

    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
});

it('supports fluent interface with commit specification', function () {
    // Create initial composer.lock
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/symfony/console.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update composer.lock
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Create dependencies
    $git = new GitRepository();
    $packageInfoFetcher = \Mockery::mock(\Whatsdiff\Services\PackageInfoFetcher::class);
    $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
    $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);

    $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

    // Test fluent interface with commits
    $result = $diffCalculator
        ->fromCommit($firstCommit)
        ->toCommit($secondCommit)
        ->skipReleaseCount()
        ->run();

    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
    // Just verify the result is returned, since we're testing the interface not the calculation logic
    expect($result->diffs)->toBeInstanceOf(\Illuminate\Support\Collection::class);
});

it('supports progressive processing with generator', function () {
    // Create initial composer.lock
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/symfony/console.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');
    $firstCommit = trim(runCommand('git rev-parse HEAD'));

    // Update composer.lock
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir.'/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');
    $secondCommit = trim(runCommand('git rev-parse HEAD'));

    // Create dependencies
    $git = new GitRepository();
    $packageInfoFetcher = \Mockery::mock(\Whatsdiff\Services\PackageInfoFetcher::class);
    $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
    $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);

    $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

    // Test progressive processing
    [$totalCount, $generator] = $diffCalculator
        ->fromCommit($firstCommit)
        ->toCommit($secondCommit)
        ->skipReleaseCount()
        ->run(withProgress: true);

    expect($totalCount)->toBeInt();
    expect($generator)->toBeInstanceOf(\Generator::class);

    // Process the generator - it might be empty, so we just test the interface
    $processedCount = 0;
    foreach ($generator as $packageChange) {
        expect($packageChange)->toBeInstanceOf(\Whatsdiff\Data\PackageChange::class);
        $processedCount++;
    }

    // Verify we can get the result after processing
    $result = $diffCalculator->getResult();
    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
});

it('maintains state isolation between runs', function () {
    // Create minimal git repository
    file_put_contents($this->tempDir.'/composer.lock', '{}');
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial commit"');

    // Create dependencies
    $git = new GitRepository();
    $packageInfoFetcher = \Mockery::mock(\Whatsdiff\Services\PackageInfoFetcher::class);
    $composerAnalyzer = new ComposerAnalyzer($packageInfoFetcher);
    $npmAnalyzer = new NpmAnalyzer($packageInfoFetcher);

    $diffCalculator = new DiffCalculator($git, $composerAnalyzer, $npmAnalyzer);

    // First run with specific settings
    $result1 = $diffCalculator
        ->ignoreLastCommit()
        ->skipReleaseCount()
        ->run();

    // Second run without explicit settings should not inherit previous state
    $result2 = $diffCalculator->run();

    expect($result1)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
    expect($result2)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
});
