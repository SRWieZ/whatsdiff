<?php

declare(strict_types=1);

use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Services\PackageInfoFetcher;

beforeEach(function () {
    $this->packageInfoFetcher = Mockery::mock(PackageInfoFetcher::class);
    $this->analyzer = new NpmAnalyzer($this->packageInfoFetcher);
});

it('extracts package versions from valid package lock', function () {
    $packageLockContent = [
        'packages' => [
            '' => [
                'name' => 'test-project',
                'version' => '1.0.0',
            ],
            'node_modules/lodash' => [
                'version' => '4.17.21',
                'resolved' => 'https://registry.npmjs.org/lodash/-/lodash-4.17.21.tgz',
            ],
            'node_modules/react' => [
                'version' => '18.2.0',
                'resolved' => 'https://registry.npmjs.org/react/-/react-18.2.0.tgz',
            ],
            'node_modules/@types/node' => [
                'version' => '18.15.0',
                'resolved' => 'https://registry.npmjs.org/@types/node/-/node-18.15.0.tgz',
            ],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([
        'lodash' => '4.17.21',
        'react' => '18.2.0',
        '@types/node' => '18.15.0',
    ]);
});

it('extracts package versions with missing packages key', function () {
    $packageLockContent = [];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([]);
});

it('filters out empty package names and versions', function () {
    $packageLockContent = [
        'packages' => [
            '' => [
                'name' => 'test-project',
                'version' => '1.0.0',
            ],
            'node_modules/lodash' => [
                'version' => '4.17.21',
            ],
            'node_modules/invalid' => [
                // Missing version
            ],
            'node_modules/' => [
                'version' => '1.0.0',
            ],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([
        'lodash' => '4.17.21',
    ]);
});

it('calculates diff with valid json', function () {
    $previousLock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.15'],
            'node_modules/moment' => ['version' => '2.29.1'],
        ],
    ]);

    $currentLock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'], // Updated
            'node_modules/react' => ['version' => '18.2.0'], // Added
            // moment removed
        ],
    ]);

    $result = $this->analyzer->calculateDiff($currentLock, $previousLock);

    expect($result)->toHaveCount(3);

    // Updated package
    expect($result['lodash'])->toBe([
        'name' => 'lodash',
        'from' => '4.17.15',
        'to' => '4.17.21',
    ]);

    // Removed package
    expect($result['moment'])->toBe([
        'name' => 'moment',
        'from' => '2.29.1',
        'to' => null,
    ]);

    // Added package
    expect($result['react'])->toBe([
        'name' => 'react',
        'from' => null,
        'to' => '18.2.0',
    ]);
});

it('calculates diff with invalid json', function () {
    $result = $this->analyzer->calculateDiff('invalid json', null);

    expect($result)->toBe([]);
});

it('calculates diff with null previous', function () {
    $currentLock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'],
        ],
    ]);

    $result = $this->analyzer->calculateDiff($currentLock, null);

    expect($result)->toHaveCount(1);
    expect($result['lodash'])->toBe([
        'name' => 'lodash',
        'from' => null,
        'to' => '4.17.21',
    ]);
});

it('filters unchanged packages in diff', function () {
    $lock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'],
        ],
    ]);

    $result = $this->analyzer->calculateDiff($lock, $lock);

    expect($result)->toBe([]);
});

it('gets releases count successfully', function () {
    $this->packageInfoFetcher
        ->shouldReceive('getNpmReleases')
        ->once()
        ->with('lodash', '4.17.15', '4.17.21')
        ->andReturn([
            ['version' => '4.17.16'],
            ['version' => '4.17.17'],
            ['version' => '4.17.18'],
            ['version' => '4.17.19'],
            ['version' => '4.17.20'],
            ['version' => '4.17.21'],
        ]);

    $result = $this->analyzer->getReleasesCount('lodash', '4.17.15', '4.17.21');

    expect($result)->toBe(6);
});


it('handles scoped packages correctly', function () {
    $packageLockContent = [
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/@babel/core' => ['version' => '7.20.0'],
            'node_modules/@types/node' => ['version' => '18.15.0'],
            'node_modules/@vue/compiler-sfc' => ['version' => '3.2.0'],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([
        '@babel/core' => '7.20.0',
        '@types/node' => '18.15.0',
        '@vue/compiler-sfc' => '3.2.0',
    ]);
});

it('handles nested dependencies correctly', function () {
    $packageLockContent = [
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'],
            'node_modules/react' => ['version' => '18.2.0'],
            'node_modules/react/node_modules/scheduler' => ['version' => '0.23.0'],
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([
        'lodash' => '4.17.21',
        'react' => '18.2.0',
        'react/scheduler' => '0.23.0',
    ]);
});

it('handles empty string in packages', function () {
    $packageLockContent = [
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'],
            'node_modules/' => ['version' => '1.0.0'], // This should be filtered out
        ],
    ];

    $result = $this->analyzer->extractPackageVersions($packageLockContent);

    expect($result)->toBe([
        'lodash' => '4.17.21',
    ]);
});

it('calculates diff with complex changes', function () {
    $previousLock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.15'],
            'node_modules/moment' => ['version' => '2.29.1'],
            'node_modules/axios' => ['version' => '0.21.1'],
            'node_modules/@types/node' => ['version' => '16.0.0'],
        ],
    ]);

    $currentLock = json_encode([
        'packages' => [
            '' => ['name' => 'test-project', 'version' => '1.0.0'],
            'node_modules/lodash' => ['version' => '4.17.21'], // Updated
            'node_modules/axios' => ['version' => '0.20.0'], // Downgraded
            'node_modules/@types/node' => ['version' => '18.15.0'], // Updated
            'node_modules/react' => ['version' => '18.2.0'], // Added
            // moment removed
        ],
    ]);

    $result = $this->analyzer->calculateDiff($currentLock, $previousLock);

    expect($result)->toHaveCount(5);

    $changes = collect($result);

    expect($changes->where('from', '!=', null)->where('to', '!=', null)->count())->toBe(3); // Updated/downgraded
    expect($changes->where('from', null)->count())->toBe(1); // Added
    expect($changes->where('to', null)->count())->toBe(1); // Removed
});
