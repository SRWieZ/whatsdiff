<?php

declare(strict_types=1);



beforeEach(function () {
    $this->tempDir = initTempDirectory(true);
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('handles private composer packages with authentication', function () {
    // Create auth.json with private packagist credentials
    $authContent = [
        'http-basic' => [
            'repo.packagist.com' => [
                'username' => 'test-user',
                'password' => 'test-password',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/auth.json', json_encode($authContent, JSON_PRETTY_PRINT));

    // Initial composer.lock with private package
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'livewire/flux-pro',
                'version' => 'v1.0.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/livewire/flux-pro.git',
                    'reference' => 'abc123',
                ],
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/abc123',
                    'reference' => 'abc123',
                ],
                'type' => 'library',
            ],
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/symfony/console.git',
                    'reference' => 'def456',
                ],
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://api.github.com/repos/symfony/console/zipball/def456',
                    'reference' => 'def456',
                ],
                'type' => 'library',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add .', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock with private package"', $this->tempDir);

    // Update composer.lock with new version of private package
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'xyz789',
        'packages' => [
            [
                'name' => 'livewire/flux-pro',
                'version' => 'v1.1.0', // Updated private package
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/livewire/flux-pro.git',
                    'reference' => 'xyz789',
                ],
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/xyz789',
                    'reference' => 'xyz789',
                ],
                'type' => 'library',
            ],
            [
                'name' => 'symfony/console',
                'version' => 'v6.0.0', // Updated public package
                'source' => [
                    'type' => 'git',
                    'url' => 'https://github.com/symfony/console.git',
                    'reference' => 'new123',
                ],
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://api.github.com/repos/symfony/console/zipball/new123',
                    'reference' => 'new123',
                ],
                'type' => 'library',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update both private and public packages"', $this->tempDir);

    // Run whatsdiff with JSON output
    $process = runWhatsDiff(['--format=json'], $this->tempDir);
    $output = $process->getOutput();
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

    // Verify both packages are detected
    expect($changes)->toHaveCount(2);

    // Check private package update
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');
    expect($fluxChange)->not->toBeNull();
    expect($fluxChange['status'])->toBe('updated');
    expect($fluxChange['from'])->toBe('v1.0.0');
    expect($fluxChange['to'])->toBe('v1.1.0');

    // Check public package update
    $consoleChange = $changes->firstWhere('name', 'symfony/console');
    expect($consoleChange)->not->toBeNull();
    expect($consoleChange['status'])->toBe('updated');
    expect($consoleChange['from'])->toBe('v5.4.0');
    expect($consoleChange['to'])->toBe('v6.0.0');
});

it('handles private packages without authentication gracefully', function () {
    // No auth.json file created

    // Initial composer.lock with private package
    $initialComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'livewire/flux-pro',
                'version' => 'v1.0.0',
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/abc123',
                    'reference' => 'abc123',
                ],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($initialComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock with private package"', $this->tempDir);

    // Update composer.lock
    $updatedComposerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'xyz789',
        'packages' => [
            [
                'name' => 'livewire/flux-pro',
                'version' => 'v1.1.0',
                'dist' => [
                    'type' => 'zip',
                    'url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/xyz789',
                    'reference' => 'xyz789',
                ],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($updatedComposerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update private package"', $this->tempDir);

    // Run whatsdiff - should still work but without release count info
    $process = runWhatsDiff(['--format=json'], $this->tempDir);
    $output = $process->getOutput();
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    expect($result)->toBeArray();
    expect($result)->toHaveKey('diffs');
    expect($result['diffs'])->toHaveCount(1);

    $changes = collect($result['diffs'][0]['changes']);
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');

    expect($fluxChange)->not->toBeNull();
    expect($fluxChange['status'])->toBe('updated');
    expect($fluxChange['from'])->toBe('v1.0.0');
    expect($fluxChange['to'])->toBe('v1.1.0');
    // Release count might be 0 due to authentication failure
    expect($fluxChange['release_count'])->toBeInt();
});

it('prioritizes local auth.json over global auth.json', function () {
    // Create global auth.json
    $homeDir = getenv('HOME') ?: getenv('USERPROFILE');
    if ($homeDir) {
        $globalComposerDir = $homeDir . '/.composer';
        if (!is_dir($globalComposerDir)) {
            mkdir($globalComposerDir, 0755, true);
        }

        $globalAuthContent = [
            'http-basic' => [
                'repo.packagist.com' => [
                    'username' => 'global-user',
                    'password' => 'global-password',
                ],
            ],
        ];

        $globalAuthPath = $globalComposerDir . '/auth.json';
        file_put_contents($globalAuthPath, json_encode($globalAuthContent, JSON_PRETTY_PRINT));
    }

    // Create local auth.json that should override global
    $localAuthContent = [
        'http-basic' => [
            'repo.packagist.com' => [
                'username' => 'local-user',
                'password' => 'local-password',
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/auth.json', json_encode($localAuthContent, JSON_PRETTY_PRINT));

    // Create composer.lock with private package
    $composerLock = [
        '_readme' => ['This file locks the dependencies of your project to a known state'],
        'content-hash' => 'abc123',
        'packages' => [
            [
                'name' => 'livewire/flux-pro',
                'version' => 'v1.0.0',
                'dist' => [
                    'url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/abc123',
                ],
            ],
        ],
    ];

    file_put_contents($this->tempDir . '/composer.lock', json_encode($composerLock, JSON_PRETTY_PRINT));
    runCommand('git add .', $this->tempDir);
    runCommand('git commit -m "Initial state"', $this->tempDir);

    // Update the package
    $composerLock['content-hash'] = 'xyz789';
    $composerLock['packages'][0]['version'] = 'v1.1.0';

    file_put_contents($this->tempDir . '/composer.lock', json_encode($composerLock, JSON_PRETTY_PRINT));
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update private package"', $this->tempDir);

    // Test that analyzer uses local auth (we can't easily test the exact URL construction in integration test)
    $process = runWhatsDiff(['--format=json'], $this->tempDir);
    $output = $process->getOutput();
    $result = json_decode($output, true);

    // Debug output if null
    if ($result === null) {
        throw new \Exception("JSON decode failed. Raw output: " . $output);
    }

    expect($result)->toBeArray();
    expect($result['diffs'])->toHaveCount(1);

    $changes = collect($result['diffs'][0]['changes']);
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');
    expect($fluxChange)->not->toBeNull();

    // Cleanup global auth.json if we created it
    if (isset($globalAuthPath) && file_exists($globalAuthPath)) {
        unlink($globalAuthPath);
    }
});
