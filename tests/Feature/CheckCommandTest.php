<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Whatsdiff\Application;

require_once __DIR__ . '/../Helpers/GitTestHelpers.php';

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/whatsdiff-check-test-' . uniqid();
    mkdir($this->tempDir, 0755, true);

    // Store original directory to restore later
    $this->originalDir = getcwd();

    // Change to temp directory before running git commands
    chdir($this->tempDir);

    // Initialize git repository
    runCommand('git init');
    runCommand('git config user.email "test@example.com"');
    runCommand('git config user.name "Test User"');
});

afterEach(function () {
    // Restore original directory
    if (isset($this->originalDir)) {
        chdir($this->originalDir);
    }

    // Clean up temporary directory
    if (is_dir($this->tempDir)) {
        if (PHP_OS_FAMILY === 'Windows') {
            // On Windows, sometimes files are locked by git/processes
            for ($i = 0; $i < 3; $i++) {
                try {
                    runCommand("rmdir /s /q \"{$this->tempDir}\"");
                    break;
                } catch (\RuntimeException $e) {
                    if ($i < 2) {
                        usleep(500000); // Wait 0.5 seconds
                        continue;
                    }
                    echo "Warning: Could not clean up temp directory after 3 attempts: " . $this->tempDir . "\n";
                }
            }
        } else {
            runCommand("rm -rf \"{$this->tempDir}\"");
        }
    }
});

it('detects package updates', function () {
    // Initial composer.lock
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

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Update symfony/console
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking for any change
    $commandTester->execute(['package' => 'symfony/console']);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);

    // Test checking for update
    $commandTester->execute(['package' => 'symfony/console', '--is-updated' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);

    // Test checking for downgrade (should be false)
    $commandTester->execute(['package' => 'symfony/console', '--is-downgraded' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('false' . PHP_EOL);
});

it('detects package downgrades', function () {
    // Initial composer.lock
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'laravel/framework',
                'version' => 'v9.0.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/laravel/framework.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Downgrade laravel/framework
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v8.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Downgrade laravel/framework"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking for downgrade
    $commandTester->execute(['package' => 'laravel/framework', '--is-downgraded' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);

    // Test checking for update (should be false)
    $commandTester->execute(['package' => 'laravel/framework', '--is-updated' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('false' . PHP_EOL);
});

it('detects package additions', function () {
    // Initial composer.lock with no packages
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Add new package
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'def456',
        'packages' => [
            [
                'name' => 'monolog/monolog',
                'version' => '2.8.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/Seldaek/monolog.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Add monolog/monolog"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking for addition
    $commandTester->execute(['package' => 'monolog/monolog', '--is-added' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);

    // Test checking for removal (should be false)
    $commandTester->execute(['package' => 'monolog/monolog', '--is-removed' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('false' . PHP_EOL);
});

it('detects package removals', function () {
    // Initial composer.lock with a package
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'guzzlehttp/guzzle',
                'version' => '7.4.0',
                'source' => ['type' => 'git', 'url' => 'https://github.com/guzzle/guzzle.git'],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Remove the package
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'def456',
        'packages' => [],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Remove guzzlehttp/guzzle"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking for removal
    $commandTester->execute(['package' => 'guzzlehttp/guzzle', '--is-removed' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);

    // Test checking for addition (should be false)
    $commandTester->execute(['package' => 'guzzlehttp/guzzle', '--is-added' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('false' . PHP_EOL);
});

it('returns false when package has no changes', function () {
    // Create initial composer.lock with symfony/console
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

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Create another commit with the SAME composer.lock (no actual changes)
    $newComposerLock = $initialComposerLock;
    $newComposerLock['content-hash'] = 'def456';
    $newComposerLock['packages'][] = [
        'name' => 'symfony/process',
        'version' => 'v5.4.0',
        'source' => ['type' => 'git', 'url' => 'https://github.com/symfony/process.git'],
    ];
    file_put_contents($this->tempDir . '/composer.lock', json_encode($newComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Added symfony/process"');

    // Add some commits without dependency changes
    file_put_contents($this->tempDir . '/README.md', '# Test Project');
    runCommand('git add README.md');
    runCommand('git commit -m "Add README"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking for any change - should be false since symfony/console hasn't actually changed
    $commandTester->execute(['package' => 'symfony/console']);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('false' . PHP_EOL);
});

it('supports quiet mode', function () {
    // Initial composer.lock
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

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Initial composer.lock"');

    // Update package
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock');
    runCommand('git commit -m "Update symfony/console"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test quiet mode
    $commandTester->execute(['package' => 'symfony/console', '--quiet' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('');

    // Test quiet mode with false result
    $commandTester->execute(['package' => 'non-existent/package', '--quiet' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::FAILURE);
    expect($commandTester->getDisplay())->toBe('');
});

it('works with npm packages', function () {
    // Initial package-lock.json
    $initialPackageLock = [
        'name' => 'test-project',
        'version' => '1.0.0',
        'lockfileVersion' => 3,
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => [
                'version' => '4.17.15',
                'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.15.tgz',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($initialPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Initial package-lock.json"');

    // Update lodash
    $updatedPackageLock = $initialPackageLock;
    $updatedPackageLock['packages']['node_modules/lodash']['version'] = '4.17.21';

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($updatedPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json');
    runCommand('git commit -m "Update lodash"');

    $app = new Application();
    $command = $app->find('check');
    $commandTester = new CommandTester($command);

    // Test checking npm package update
    $commandTester->execute(['package' => 'lodash', '--is-updated' => true]);
    expect($commandTester->getStatusCode())->toBe(Command::SUCCESS);
    expect($commandTester->getDisplay())->toBe('true' . PHP_EOL);
});

it('returns error code 2 when git repository is not found', function () {
    // Change to a non-git directory BEFORE creating the application
    $nonGitDir = sys_get_temp_dir() . '/non-git-' . uniqid();
    mkdir($nonGitDir, 0755, true);
    $originalDir = getcwd();
    chdir($nonGitDir);

    try {
        $app = new Application();
        $command = $app->find('check');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['package' => 'symfony/console']);
        expect($commandTester->getStatusCode())->toBe(2);
        expect($commandTester->getDisplay())->toContain('Error:');
    } finally {
        chdir($originalDir);
        rmdir($nonGitDir);
    }
});
