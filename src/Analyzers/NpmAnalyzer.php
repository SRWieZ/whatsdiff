<?php

declare(strict_types=1);

namespace Whatsdiff\Analyzers;

use Whatsdiff\Analyzers\Exceptions\PackageInformationsException;
use Whatsdiff\Services\PackageInfoFetcher;

class NpmAnalyzer
{
    private PackageInfoFetcher $packageInfoFetcher;

    public function __construct(PackageInfoFetcher $packageInfoFetcher)
    {
        $this->packageInfoFetcher = $packageInfoFetcher;
    }

    public function extractPackageVersions(array $packageLockContent): array
    {
        return collect($packageLockContent['packages'] ?? [])
            ->filter(fn ($package, $key) => !empty($key) && !empty($package['version']))
            ->mapWithKeys(fn ($package, $key) => [
                str_replace('node_modules/', '', $key) => $package['version'],
            ])
            ->filter(fn ($version, $name) => !empty($name))
            ->toArray();
    }

    public function calculateDiff(string $lastLockContent, ?string $previousLockContent): array
    {
        $last = json_decode($lastLockContent, true);
        $previous = json_decode($previousLockContent ?? '{}', true);

        // Handle case where json_decode fails (invalid JSON or empty content)
        if ($last === null) {
            return [];
        }
        if ($previous === null) {
            $previous = [];
        }

        $lastPackages = $this->extractPackageVersions($last);
        $previousPackages = $this->extractPackageVersions($previous);

        $diff = collect($previousPackages)
            ->mapWithKeys(fn ($version, $name) => [
                $name => [
                    'name' => $name,
                    'from' => $version,
                    'to' => $lastPackages[$name] ?? null,
                ],
            ]);

        $newPackages = collect($lastPackages)
            ->diffKeys($previousPackages)
            ->mapWithKeys(fn ($version, $name) => [
                $name => [
                    'name' => $name,
                    'from' => null,
                    'to' => $version,
                ],
            ]);

        return $diff->merge($newPackages)
            ->filter(fn ($el) => $el['from'] !== $el['to'])
            ->sortKeys()
            ->toArray();
    }

    public function getReleasesCount(string $package, string $from, string $to): ?int
    {
        try {
            $releases = $this->packageInfoFetcher->getNpmReleases($package, $from, $to);
        } catch (PackageInformationsException $e) {
            return  null;
        }

        return count($releases);
    }
}
