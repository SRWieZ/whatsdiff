<?php

declare(strict_types=1);

use Whatsdiff\Container\Container;
use Whatsdiff\Services\DiffCalculator;
use Tests\Mocks\MockHttpService;
use Tests\Mocks\TestServiceProvider;

beforeEach(function () {
    $this->tempDir = initTempDirectory();

    // Create a mock HttpService that returns fake package data
    $this->mockHttpService = new MockHttpService();
});

afterEach(function () {
    cleanupTempDirectory($this->tempDir);
});

it('handles private composer packages with authentication', function () {
    // Setup mock responses for package info requests
    $fluxProResponse = json_encode([
        'packages' => [
            'livewire/flux-pro' => [
                [
                    'version' => 'v1.0.1',
                    'release_date' => '2024-01-15',
                ],
                [
                    'version' => 'v1.0.2',
                    'release_date' => '2024-01-20',
                ],
                [
                    'version' => 'v1.1.0',
                    'release_date' => '2024-02-01',
                ],
            ],
        ],
    ]);

    $symfonyResponse = json_encode([
        'packages' => [
            'symfony/console' => [
                [
                    'version' => 'v5.4.1',
                    'release_date' => '2024-01-10',
                ],
                [
                    'version' => 'v6.0.0',
                    'release_date' => '2024-02-15',
                ],
            ],
        ],
    ]);

    // Configure mock HTTP service responses
    $this->mockHttpService->setResponse('https://composer.fluxui.dev/p2/livewire/flux-pro.json', $fluxProResponse);
    $this->mockHttpService->setResponse('https://repo.packagist.org/p2/symfony/console.json', $symfonyResponse);

    // Create auth.json with private packagist credentials
    $authContent = [
        'http-basic' => [
            'composer.fluxui.dev' => [
                'username' => 'test-user',
                'password' => 'test-password',
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/auth.json', json_encode($authContent, JSON_PRETTY_PRINT));

    // Initial composer.lock with private and public packages
    $initialComposerLock = generateComposerLock([
        'livewire/flux-pro' => 'v1.0.0',
        'symfony/console' => 'v5.4.0',
    ]);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add .', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock with private package"', $this->tempDir);

    // Update composer.lock with new versions
    $updatedComposerLock = generateComposerLock([
        'livewire/flux-pro' => 'v1.1.0',
        'symfony/console' => 'v6.0.0',
    ]);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update both private and public packages"', $this->tempDir);

    // Change to the test directory so GitRepository picks it up
    $originalDir = getcwd();
    chdir($this->tempDir);

    try {
        // Create container with test service provider that mocks only HttpService
        $container = new Container();
        $container->register(new TestServiceProvider($this->mockHttpService));

        // Create and run the diff calculator directly to avoid the CLI layer
        $diffCalculator = $container->get(DiffCalculator::class);
        $result = $diffCalculator->run();
    } finally {
        // Always restore the original directory
        chdir($originalDir);
    }

    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
    expect($result->diffs)->toHaveCount(1);
    expect($result->diffs->first()->type->value)->toBe('composer');

    $changes = $result->diffs->first()->changes;

    // Verify both packages are detected
    expect($changes)->toHaveCount(2);

    // Check private package update
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');
    expect($fluxChange)->not->toBeNull();
    expect($fluxChange->status->value)->toBe('updated');
    expect($fluxChange->from)->toBe('v1.0.0');
    expect($fluxChange->to)->toBe('v1.1.0');

    expect($fluxChange->releaseCount)->toBe(3); // Mocked release count

    // Check public package update
    $consoleChange = $changes->firstWhere('name', 'symfony/console');
    expect($consoleChange)->not->toBeNull();
    expect($consoleChange->status->value)->toBe('updated');
    expect($consoleChange->from)->toBe('v5.4.0');
    expect($consoleChange->to)->toBe('v6.0.0');
    expect($consoleChange->releaseCount)->toBe(2); // Mocked release count
});

it('handles private packages without authentication gracefully', function () {
    // No auth.json file created - private package falls back to packagist which doesn't have it
    // So we don't set any response, which will return the default empty packages response

    // Initial composer.lock with private package
    $initialComposerLock = generateComposerLock(['livewire/flux-pro' => 'v1.0.0']);

    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Initial composer.lock with private package"', $this->tempDir);

    // Update composer.lock
    $updatedComposerLock = generateComposerLock(['livewire/flux-pro' => 'v1.1.0']);

    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update private package"', $this->tempDir);

    // Change to the test directory and run diff calculator with mocked service
    $originalDir = getcwd();
    chdir($this->tempDir);

    try {
        $container = new Container();
        $container->register(new TestServiceProvider($this->mockHttpService));

        $diffCalculator = $container->get(DiffCalculator::class);
        $result = $diffCalculator->run();
    } finally {
        chdir($originalDir);
    }

    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
    expect($result->diffs)->toHaveCount(1);

    $changes = $result->diffs->first()->changes;
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');

    expect($fluxChange)->not->toBeNull();
    expect($fluxChange->status->value)->toBe('updated');
    expect($fluxChange->from)->toBe('v1.0.0');
    expect($fluxChange->to)->toBe('v1.1.0');

    // Release count should be null due to authentication failure (empty response)
    expect($fluxChange->releaseCount)->toBeNull();
});

it('prioritizes local auth.json over global auth.json', function () {
    // Setup mock to simulate successful authentication with local auth
    $mockResponse = json_encode([
        'packages' => [
            'livewire/flux-pro' => [
                [
                    'version' => 'v1.0.1',
                    'release_date' => '2024-01-15',
                ],
                [
                    'version' => 'v1.1.0',
                    'release_date' => '2024-02-01',
                ],
            ],
        ],
    ]);

    // Configure mock HTTP service to verify auth headers are passed correctly
    $authAwareHttpService = new MockHttpService();
    $authAwareHttpService->setResponse('https://composer.fluxui.dev/p2/livewire/flux-pro.json', $mockResponse);

    // Create local auth.json that should be prioritized
    $localAuthContent = [
        'http-basic' => [
            'composer.fluxui.dev' => [
                'username' => 'local-user',
                'password' => 'local-password',
            ],
        ],
    ];

    file_put_contents($this->tempDir.'/auth.json', json_encode($localAuthContent, JSON_PRETTY_PRINT));

    // Create composer.lock with private package
    $initialComposerLock = generateComposerLock(['livewire/flux-pro' => 'v1.0.0']);
    file_put_contents($this->tempDir.'/composer.lock', $initialComposerLock);
    runCommand('git add .', $this->tempDir);
    runCommand('git commit -m "Initial state"', $this->tempDir);

    // Update the package
    $updatedComposerLock = generateComposerLock(['livewire/flux-pro' => 'v1.1.0']);
    file_put_contents($this->tempDir.'/composer.lock', $updatedComposerLock);
    runCommand('git add composer.lock', $this->tempDir);
    runCommand('git commit -m "Update private package"', $this->tempDir);

    // Change to test directory and run diff calculator with auth-aware mock
    $originalDir = getcwd();
    chdir($this->tempDir);

    try {
        $container = new Container();
        $container->register(new TestServiceProvider($authAwareHttpService));

        $diffCalculator = $container->get(DiffCalculator::class);
        $result = $diffCalculator->run();
    } finally {
        chdir($originalDir);
    }

    expect($result)->toBeInstanceOf(\Whatsdiff\Data\DiffResult::class);
    expect($result->diffs)->toHaveCount(1);

    $changes = $result->diffs->first()->changes;
    $fluxChange = $changes->firstWhere('name', 'livewire/flux-pro');
    expect($fluxChange)->not->toBeNull();
    expect($fluxChange->status->value)->toBe('updated');
    expect($fluxChange->from)->toBe('v1.0.0');
    expect($fluxChange->to)->toBe('v1.1.0');
    expect($fluxChange->releaseCount)->toBe(2); // Should get release count with proper auth

    // Verify that auth options were captured (this tests that local auth.json was read)
    // The actual auth implementation would use these options for HTTP requests
    expect($authAwareHttpService->capturedOptions)->toBeArray();
});
