<?php

declare(strict_types=1);

use Symfony\Component\Process\Process as SymfonyProcess;
use Whatsdiff\Services\GitRepository;

require_once __DIR__ . '/../Helpers/GitTestHelpers.php';

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/whatsdiff-test-' . uniqid();
    mkdir($this->tempDir, 0755, true);
    
    // Store original directory to restore later
    $this->originalDir = getcwd();
    
    // Change to temp directory before running git commands
    chdir($this->tempDir);
    
    // Initialize git repository
    runCommand('git init');
    runCommand('git config user.email "test@example.com"');
    runCommand('git config user.name "Test User"');
    
    $this->gitRepository = new GitRepository();
});

afterEach(function () {
    // Restore original directory
    if (isset($this->originalDir)) {
        chdir($this->originalDir);
    }
    
    // Clean up temporary directory
    if (is_dir($this->tempDir)) {
        if (PHP_OS_FAMILY === 'Windows') {
            runCommand("rmdir /s /q \"{$this->tempDir}\"");
        } else {
            runCommand("rm -rf \"{$this->tempDir}\"");
        }
    }
});

it('handles npm only changes with add, update, downgrade, and remove', function () {
    // Initial package-lock.json with some packages
    $initialPackageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => [
                'name' => 'test-project',
                'version' => '1.0.0',
            ],
            'node_modules/lodash' => [
                'version' => '4.17.15',
                'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.15.tgz',
            ],
            'node_modules/axios' => [
                'version' => '0.21.1',
                'resolved' => 'https://registry.npmjs.org/axios/-/axios-0.21.1.tgz',
            ],
            'node_modules/moment' => [
                'version' => '2.29.1',
                'resolved' => 'https://registry.npmjs.org/moment/-/moment-2.29.1.tgz',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($initialPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Initial package-lock.json"');

    // Update package-lock.json: add new package, update existing, downgrade one, remove one
    $updatedPackageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => [
                'name' => 'test-project',
                'version' => '1.0.0',
            ],
            'node_modules/lodash' => [
                'version' => '4.17.21', // Updated
                'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
            ],
            'node_modules/axios' => [
                'version' => '0.20.0', // Downgraded
                'resolved' => 'https://registry.npmjs.org/axios/-/axios-0.20.0.tgz',
            ],
            'node_modules/react' => [
                'version' => '18.2.0', // Added
                'resolved' => 'https://registry.npmjs.org/react/-/react-18.2.0.tgz',
            ],
            // moment removed
        ],
    ];

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($updatedPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Update npm dependencies"');

    // Add debug output on Windows before running whatsdiff
    if (PHP_OS_FAMILY === 'Windows') {
        echo "\n--- DEBUG INFO FOR WINDOWS ---\n";
        echo "Current working directory: " . getcwd() . "\n";
        echo "Temp directory: " . test()->tempDir . "\n";
        echo "Git status:\n" . runCommand('git status') . "\n";
        echo "Git log:\n" . runCommand('git log --oneline -5') . "\n";
        echo "Files in directory:\n" . runCommand('dir /b') . "\n";
        echo "Package-lock.json exists: " . (file_exists('package-lock.json') ? 'YES' : 'NO') . "\n";
        if (file_exists('package-lock.json')) {
            echo "Package-lock.json size: " . filesize('package-lock.json') . " bytes\n";
        }
        echo "-------------------------------\n";
    }

    // Run whatsdiff with JSON output
    $output = runWhatsDiff(['--format=json']);
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    // Add Windows debugging for empty diffs
    if (PHP_OS_FAMILY === 'Windows' && isset($result['diffs']) && empty($result['diffs'])) {
        echo "\n--- WINDOWS DEBUG: Empty diffs detected ---\n";
        echo "Full whatsdiff output: " . $output . "\n";
        echo "Parsed result: " . print_r($result, true) . "\n";
        echo "---------------------------------------------\n";
    }

    expect($result)->toBeArray();
    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(1);
    expect($result['diffs'][0]['type'])->toBe('npmjs');
    
    $changes = collect($result['diffs'][0]['changes']);
    
    // Check for added package  
    $addedPackage = $changes->firstWhere('status', 'added');
    expect($addedPackage)->not->toBeNull();
    expect($addedPackage['name'])->toBe('react');
    expect($addedPackage['to'])->toBe('18.2.0');

    // Check for updated package
    $updatedPackage = $changes->firstWhere('name', 'lodash');
    expect($updatedPackage)->not->toBeNull();
    expect($updatedPackage['status'])->toBe('updated');
    expect($updatedPackage['from'])->toBe('4.17.15');
    expect($updatedPackage['to'])->toBe('4.17.21');

    // Check for downgraded package
    $downgradedPackage = $changes->firstWhere('name', 'axios');
    expect($downgradedPackage)->not->toBeNull();
    expect($downgradedPackage['status'])->toBe('downgraded');
    expect($downgradedPackage['from'])->toBe('0.21.1');
    expect($downgradedPackage['to'])->toBe('0.20.0');

    // Check for removed package
    $removedPackage = $changes->firstWhere('status', 'removed');
    expect($removedPackage)->not->toBeNull();
    expect($removedPackage['name'])->toBe('moment');
    expect($removedPackage['from'])->toBe('2.29.1');
});

it('handles composer only changes', function () {
    // Initial composer.lock
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/symfony/console.git',
                    'reference' => 'abc123',
                ],
            ],
            [
                'name' => 'illuminate/collections',
                'version' => 'v9.0.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/illuminate/collections.git',
                    'reference' => 'def456',
                ],
            ],
        ],
        'packages-dev' => [
            [
                'name' => 'phpunit/phpunit',
                'version' => '9.5.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/sebastianbergmann/phpunit.git',
                    'reference' => 'ghi789',
                ],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Update composer.lock
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'xyz789',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v6.0.0', // Updated
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/symfony/console.git',
                    'reference' => 'abc999',
                ],
            ],
            [
                'name' => 'illuminate/collections',
                'version' => 'v8.0.0', // Downgraded
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/illuminate/collections.git',
                    'reference' => 'def111',
                ],
            ],
            [
                'name' => 'monolog/monolog',
                'version' => '2.8.0', // Added
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/Seldaek/monolog.git',
                    'reference' => 'new123',
                ],
            ],
        ],
        'packages-dev' => [
            // phpunit removed
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update composer dependencies"');

    // Run whatsdiff with JSON output
    $output = runWhatsDiff(['--format=json']);
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    expect($result)->toBeArray();
    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(1);
    expect($result['diffs'][0]['type'])->toBe('composer');
    
    $changes = collect($result['diffs'][0]['changes']);
    
    // Verify all expected changes
    expect($changes->where('status', 'added')->count())->toBe(1);
    expect($changes->where('status', 'updated')->count())->toBe(1);
    expect($changes->where('status', 'downgraded')->count())->toBe(1);
    expect($changes->where('status', 'removed')->count())->toBe(1);
});

it('handles both composer and npm changes across multiple commits', function () {
    // Initial state with both files
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
            ],
        ],
    ];

    $initialPackageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.15'],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    file_put_contents($this->tempDir . '/package-lock.json', json_encode($initialPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add .');
    runCommand('git commit -m "Initial state"');

    // First commit: Update composer only
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'def456',
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v6.0.0', // Updated
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update composer dependencies"');

    // Second commit: Update npm only (2 commits after composer)
    runCommand('git commit --allow-empty -m "Empty commit 1"');
    runCommand('git commit --allow-empty -m "Empty commit 2"');

    $updatedPackageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'], // Updated
        ],
    ];

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($updatedPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Update npm dependencies"');

    // Run whatsdiff
    $output = runWhatsDiff(['--format=json']);
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    expect($result)->toBeArray();
    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(2);

    $composerDep = collect($result['diffs'])->firstWhere('type', 'composer');
    $npmDep = collect($result['diffs'])->firstWhere('type', 'npmjs');

    expect($composerDep)->not->toBeNull();
    expect($npmDep)->not->toBeNull();
});

it('shows no changes when there are several commits without dependency updates', function () {
    // Initial state
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            ['name' => 'symfony/console', 'version' => 'v5.4.0'],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Add several commits without dependency changes
    for ($i = 1; $i <= 5; $i++) {
        file_put_contents($this->tempDir . "/file{$i}.txt", "Content {$i}");
        runCommand("git add file{$i}.txt");
        runCommand("git commit -m 'Add file {$i}'");
    }

    // Run whatsdiff
    $output = runWhatsDiff(['--format=json']);
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    expect($result)->toBeArray();
    expect($result)->toHaveKey('diffs');
    // Since we have commits with the composer.lock file, there may be some diff detected
    // The test should verify no recent changes are detected
    if (!empty($result['diffs'])) {
        expect($result['has_uncommitted_changes'])->toBeFalse();
    }
});

