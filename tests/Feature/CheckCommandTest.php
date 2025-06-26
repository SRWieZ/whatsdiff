<?php

declare(strict_types=1);

use Symfony\Component\Console\Command\Command;
use Whatsdiff\Services\ProcessService;

beforeEach(function () {
    $this->tempDir = initTempDirectory(true);
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Update symfony/console
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update symfony/console"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking for any change
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);

    // Test checking for update
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);

    // Test checking for downgrade (should be false)
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console', '--is-downgraded'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false' . PHP_EOL);
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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Downgrade laravel/framework
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v8.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Downgrade laravel/framework"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking for downgrade
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'laravel/framework', '--is-downgraded'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);

    // Test checking for update (should be false)
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'laravel/framework', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false' . PHP_EOL);
});

it('detects package additions', function () {
    // Initial composer.lock with no packages
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Add monolog/monolog"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking for addition
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'monolog/monolog', '--is-added'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);

    // Test checking for removal (should be false)
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'monolog/monolog', '--is-removed'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false' . PHP_EOL);
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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Remove the package
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'def456',
        'packages' => [],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Remove guzzlehttp/guzzle"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking for removal
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'guzzlehttp/guzzle', '--is-removed'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);

    // Test checking for addition (should be false)
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'guzzlehttp/guzzle', '--is-added'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false' . PHP_EOL);
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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Create another commit with the SAME composer.lock (no actual changes)
    $newComposerLock = $initialComposerLock;
    $newComposerLock['content-hash'] = 'def456';
    $newComposerLock['packages'][] = [
        'name' => 'symfony/process',
        'version' => 'v5.4.0',
        'source' => ['type' => 'git', 'url' => 'https://github.com/symfony/process.git'],
    ];
    file_put_contents($this->tempDir . '/composer.lock', json_encode($newComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Added symfony/process"', $this->tempDir);

    // Add some commits without dependency changes
    file_put_contents($this->tempDir . '/README.md', '# Test Project');
    runCommand('git add README.md', $this->tempDir);
    runCommand('git commit -m "Add README"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking for any change - should be false since symfony/console hasn't actually changed
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('false' . PHP_EOL);
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
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock"', $this->tempDir);

    // Update package
    $updatedComposerLock = $initialComposerLock;
    $updatedComposerLock['packages'][0]['version'] = 'v6.0.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update symfony/console"', $this->tempDir);

    $processService = new ProcessService();

    // Test quiet mode
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console', '--quiet'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('');

    // Test quiet mode with false result
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'non-existent/package', '--quiet'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::FAILURE);
    expect($process->getOutput())->toBe('');
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
    runCommand('git add package-lock.json', $this->tempDir);
    runCommand('git commit -m "Initial package-lock.json"', $this->tempDir);

    // Update lodash
    $updatedPackageLock = $initialPackageLock;
    $updatedPackageLock['packages']['node_modules/lodash']['version'] = '4.17.21';

    file_put_contents($this->tempDir . '/package-lock.json', json_encode($updatedPackageLock, JSON_PRETTY_PRINT));
    runCommand('git add package-lock.json', $this->tempDir);
    runCommand('git commit -m "Update lodash"', $this->tempDir);

    $processService = new ProcessService();

    // Test checking npm package update
    $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'lodash', '--is-updated'], $this->tempDir);
    expect($process->getExitCode())->toBe(Command::SUCCESS);
    expect($process->getOutput())->toBe('true' . PHP_EOL);
});

it('returns error code 2 when git repository is not found', function () {
    // Create a non-git directory
    $nonGitDir = initTempDirectory(false); // false = do not initialize git

    try {
        $processService = new ProcessService();

        $process = $processService->php([realpath(__DIR__ . '/../../bin/whatsdiff'), 'check', 'symfony/console'], $nonGitDir);
        dump($process->getExitCode(), $process->getOutput(), $process->getErrorOutput());
        expect($process->getExitCode())->toBe(Command::INVALID);
        expect($process->getOutput() . $process->getErrorOutput())->toContain('Error:');
    } finally {
        cleanupTempDirectory($nonGitDir);
    }
});
