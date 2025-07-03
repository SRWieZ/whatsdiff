<?php

declare(strict_types=1);

namespace Whatsdiff\Analyzers;

use Whatsdiff\Analyzers\Exceptions\PackageInformationsException;
use Whatsdiff\Services\PackageInfoFetcher;

class ComposerAnalyzer
{
    private PackageInfoFetcher $packageInfoFetcher;

    public function __construct(PackageInfoFetcher $packageInfoFetcher)
    {
        $this->packageInfoFetcher = $packageInfoFetcher;
    }

    public function extractPackageVersions(array $composerLockContent): array
    {
        return collect($composerLockContent['packages'] ?? [])
            ->merge($composerLockContent['packages-dev'] ?? [])
            ->mapWithKeys(fn ($package) => [$package['name'] => $package['version']])
            ->toArray();
    }

    public function calculateDiff(string $lastLockContent, ?string $previousLockContent): array
    {
        $lastLock = json_decode($lastLockContent, true);
        $previousLock = json_decode($previousLockContent ?? '{}', true);

        // Handle case where json_decode fails (invalid JSON or empty content)
        if ($lastLock === null) {
            return [];
        }
        if ($previousLock === null) {
            $previousLock = [];
        }

        $last = $this->extractPackageVersions($lastLock);
        $previous = $this->extractPackageVersions($previousLock);

        $diff = collect($previous)
            ->mapWithKeys(fn ($version, $name) => [
                $name => [
                    'name' => $name,
                    'from' => $version,
                    'to' => $last[$name] ?? null,
                    'infos_url' => $this->getPackageUrl($name, $lastLock),
                ],
            ]);

        $newPackages = collect($last)
            ->diffKeys($previous)
            ->mapWithKeys(fn ($version, $name) => [
                $name => [
                    'name' => $name,
                    'from' => null,
                    'to' => $version,
                    'infos_url' => $this->getPackageUrl($name, $lastLock),
                ],
            ]);

        return $diff->merge($newPackages)
            ->filter(fn ($el) => $el['from'] !== $el['to'])
            ->sortKeys()
            ->toArray();
    }

    public function getReleasesCount(string $package, string $from, string $to, string $url): ?int
    {
        try {
            $releases = $this->packageInfoFetcher->getComposerReleases($package, $from, $to, $url);
        } catch (PackageInformationsException $e) {
            return  null;
        }

        return count($releases);
    }

    private function getPackageUrl(string $name, array $composerLock): string
    {
        // Default packagist url
        $url = PackageManagerType::COMPOSER->getRegistryUrl($name);

        $packageInfo = collect($composerLock['packages'] ?? [])
            ->merge($composerLock['packages-dev'] ?? [])
            ->first(fn ($package) => $package['name'] === $name);

        if (!$packageInfo) {
            return $url;
        }

        $authJson = $this->loadAuthJson();
        $distUrlDomain = parse_url($packageInfo['dist']['url'] ?? '', PHP_URL_HOST);

        // Check for private repository authentication
        if (!empty($authJson['http-basic'][$distUrlDomain])) {
            $username = urlencode($authJson['http-basic'][$distUrlDomain]['username']);
            $password = urlencode($authJson['http-basic'][$distUrlDomain]['password']);

            $url = "https://{$username}:{$password}@{$distUrlDomain}/p2/{$name}.json";
        }

        return $url;
    }

    private function loadAuthJson(): array
    {
        $currentDir = getcwd() ?: '';
        $localAuthPath = $currentDir . DIRECTORY_SEPARATOR . 'auth.json';

        $HOME = getenv('HOME') ?: getenv('USERPROFILE');
        $globalAuthPath = $HOME . DIRECTORY_SEPARATOR . '.composer/auth.json';

        $localAuth = [];
        $globalAuth = [];

        if (file_exists($localAuthPath)) {
            $content = file_get_contents($localAuthPath);
            if ($content !== false) {
                $localAuth = json_decode($content, true) ?: [];
            }
        }

        if (file_exists($globalAuthPath)) {
            $content = file_get_contents($globalAuthPath);
            if ($content !== false) {
                $globalAuth = json_decode($content, true) ?: [];
            }
        }

        return collect($globalAuth)->merge($localAuth)->only('http-basic')->toArray();
    }
}
