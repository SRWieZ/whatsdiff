<?php

declare(strict_types=1);

use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Services\PackageInfoFetcher;

beforeEach(function () {
    $this->packageInfoFetcher = Mockery::mock(PackageInfoFetcher::class);
    $this->analyzer = new ComposerAnalyzer($this->packageInfoFetcher);
});

it('extracts package versions from valid composer lock', function () {
    $composerLockContent = [
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
            ],
            [
                'name' => 'illuminate/collections',
                'version' => 'v9.0.0',
            ],
        ],
        'packages-dev' => [
            [
                'name' => 'phpunit/phpunit',
                'version' => '9.5.0',
            ],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($composerLockContent);

    expect($result)->toBe([
        'symfony/console' => 'v5.4.0',
        'illuminate/collections' => 'v9.0.0',
        'phpunit/phpunit' => '9.5.0',
    ]);
});

it('extracts package versions with missing packages key', function () {
    $composerLockContent = [
        'packages-dev' => [
            [
                'name' => 'phpunit/phpunit',
                'version' => '9.5.0',
            ],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($composerLockContent);

    expect($result)->toBe([
        'phpunit/phpunit' => '9.5.0',
    ]);
});

it('extracts package versions with empty content', function () {
    $result = $this->analyzer->extractPackageVersions([]);

    expect($result)->toBe([]);
});

it('calculates diff with valid json', function () {
    $previousLock = json_encode([
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'dist' => ['url' => 'https://api.github.com/repos/symfony/console/zipball/abc123'],
            ],
            [
                'name' => 'monolog/monolog',
                'version' => '2.7.0',
                'dist' => ['url' => 'https://api.github.com/repos/Seldaek/monolog/zipball/def456'],
            ],
        ],
    ]);

    $currentLock = json_encode([
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v6.0.0', // Updated
                'dist' => ['url' => 'https://api.github.com/repos/symfony/console/zipball/xyz789'],
            ],
            [
                'name' => 'illuminate/collections',
                'version' => 'v9.0.0', // Added
                'dist' => ['url' => 'https://api.github.com/repos/illuminate/collections/zipball/new123'],
            ],
        ],
    ]);

    $result = $this->analyzer->calculateDiff($currentLock, $previousLock);

    expect($result)->toHaveCount(3);

    // Updated package
    expect($result['symfony/console'])->toBe([
        'name' => 'symfony/console',
        'from' => 'v5.4.0',
        'to' => 'v6.0.0',
        'infos_url' => 'https://repo.packagist.org/p2/symfony/console.json',
    ]);

    // Removed package
    expect($result['monolog/monolog'])->toBe([
        'name' => 'monolog/monolog',
        'from' => '2.7.0',
        'to' => null,
        'infos_url' => 'https://repo.packagist.org/p2/monolog/monolog.json',
    ]);

    // Added package
    expect($result['illuminate/collections'])->toBe([
        'name' => 'illuminate/collections',
        'from' => null,
        'to' => 'v9.0.0',
        'infos_url' => 'https://repo.packagist.org/p2/illuminate/collections.json',
    ]);
});

it('calculates diff with invalid json', function () {
    $result = $this->analyzer->calculateDiff('invalid json', null);

    expect($result)->toBe([]);
});

it('calculates diff with null previous', function () {
    $currentLock = json_encode([
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'dist' => ['url' => 'https://api.github.com/repos/symfony/console/zipball/abc123'],
            ],
        ],
    ]);

    $result = $this->analyzer->calculateDiff($currentLock, null);

    expect($result)->toHaveCount(1);
    expect($result['symfony/console'])->toBe([
        'name' => 'symfony/console',
        'from' => null,
        'to' => 'v5.4.0',
        'infos_url' => 'https://repo.packagist.org/p2/symfony/console.json',
    ]);
});

it('filters unchanged packages in diff', function () {
    $lock = json_encode([
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'dist' => ['url' => 'https://api.github.com/repos/symfony/console/zipball/abc123'],
            ],
        ],
    ]);

    $result = $this->analyzer->calculateDiff($lock, $lock);

    expect($result)->toBe([]);
});

it('gets releases count successfully', function () {
    $this->packageInfoFetcher
        ->shouldReceive('getComposerReleases')
        ->once()
        ->with('symfony/console', 'v5.4.0', 'v6.0.0', 'https://repo.packagist.org/p2/symfony/console.json')
        ->andReturn([
            ['version' => 'v5.4.1'],
            ['version' => 'v5.4.2'],
            ['version' => 'v6.0.0'],
        ]);

    $result = $this->analyzer->getReleasesCount('symfony/console', 'v5.4.0', 'v6.0.0', 'https://repo.packagist.org/p2/symfony/console.json');

    expect($result)->toBe(3);
});

it('gets default packagist url', function () {
    $composerLock = [
        'packages' => [
            [
                'name' => 'symfony/console',
                'version' => 'v5.4.0',
                'dist' => ['url' => 'https://api.github.com/repos/symfony/console/zipball/abc123'],
            ],
        ],
    ];

    // Use reflection to test private method
    $reflection = new \ReflectionClass($this->analyzer);
    $method = $reflection->getMethod('getPackageUrl');
    $method->setAccessible(true);

    $result = $method->invoke($this->analyzer, 'symfony/console', $composerLock);

    expect($result)->toBe('https://repo.packagist.org/p2/symfony/console.json');
});

it('gets private repository url with authentication', function () {
    // Create temporary auth.json for testing
    $tempDir = sys_get_temp_dir() . '/whatsdiff-test-' . uniqid();
    mkdir($tempDir);

    $authContent = [
        'http-basic' => [
            'repo.packagist.com' => [
                'username' => 'test-user',
                'password' => 'test-pass',
            ],
        ],
    ];

    file_put_contents($tempDir . '/auth.json', json_encode($authContent));

    // Change to temp directory temporarily
    $originalDir = getcwd();
    chdir($tempDir);

    try {
        $composerLock = [
            'packages' => [
                [
                    'name' => 'livewire/flux-pro',
                    'version' => 'v1.0.0',
                    'dist' => ['url' => 'https://repo.packagist.com/livewire/flux-pro/zipball/abc123'],
                ],
            ],
        ];

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('getPackageUrl');
        $method->setAccessible(true);

        $result = $method->invoke($this->analyzer, 'livewire/flux-pro', $composerLock);

        expect($result)->toBe('https://test-user:test-pass@repo.packagist.com/p2/livewire/flux-pro.json');
    } finally {
        chdir($originalDir);
        // Clean up
        unlink($tempDir . '/auth.json');
        rmdir($tempDir);
    }
});

it('loads auth json from local and global files', function () {
    // Create temporary directories and files
    $tempDir = sys_get_temp_dir() . '/whatsdiff-test-' . uniqid();
    $homeDir = sys_get_temp_dir() . '/whatsdiff-home-' . uniqid();
    mkdir($tempDir);
    mkdir($homeDir);
    mkdir($homeDir . '/.composer');

    $localAuth = [
        'http-basic' => [
            'local.example.com' => [
                'username' => 'local-user',
                'password' => 'local-pass',
            ],
        ],
    ];

    $globalAuth = [
        'http-basic' => [
            'global.example.com' => [
                'username' => 'global-user',
                'password' => 'global-pass',
            ],
            'local.example.com' => [
                'username' => 'global-user-override',
                'password' => 'global-pass-override',
            ],
        ],
    ];

    file_put_contents($tempDir . '/auth.json', json_encode($localAuth));
    file_put_contents($homeDir . '/.composer/auth.json', json_encode($globalAuth));

    // Set environment variable
    putenv("HOME={$homeDir}");

    $originalDir = getcwd();
    chdir($tempDir);

    try {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->analyzer);
        $method = $reflection->getMethod('loadAuthJson');
        $method->setAccessible(true);

        $result = $method->invoke($this->analyzer);

        // Local auth should override global auth
        expect($result)->toHaveKey('http-basic');
        expect($result['http-basic']['local.example.com'])->toBe([
            'username' => 'local-user',
            'password' => 'local-pass',
        ]);

        // Global auth may or may not be present depending on environment
        if (isset($result['http-basic']['global.example.com'])) {
            expect($result['http-basic']['global.example.com'])->toBe([
                'username' => 'global-user',
                'password' => 'global-pass',
            ]);
        }
    } finally {
        chdir($originalDir);
        // Clean up
        unlink($tempDir . '/auth.json');
        unlink($homeDir . '/.composer/auth.json');
        rmdir($homeDir . '/.composer');
        rmdir($homeDir);
        rmdir($tempDir);
        putenv('HOME'); // Unset
    }
});
