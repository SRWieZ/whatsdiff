<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\Comparator;

class PackageInfoFetcher
{
    public function getComposerReleases(string $package, string $from, string $to, string $url): array
    {
        $packageInfos = file_get_contents($url);

        if ($packageInfos === false) {
            throw new \RuntimeException("Failed to fetch package information for {$package}");
        }

        $packageData = json_decode($packageInfos, true);

        if (!isset($packageData['packages'][$package])) {
            return [];
        }

        $versions = $packageData['packages'][$package];
        $returnVersions = [];

        foreach ($versions as $info) {
            $version = $info['version'];

            if (Comparator::greaterThan($version, $from) && Comparator::lessThan($version, $to)) {
                $returnVersions[] = $version;
            }
        }

        return $returnVersions;
    }

    public function getNpmReleases(string $package, string $from, string $to): array
    {
        $url = 'https://registry.npmjs.org/' . urlencode($package);
        $packageInfos = file_get_contents($url);

        if ($packageInfos === false) {
            throw new \RuntimeException("Failed to fetch package information for {$package}");
        }

        $packageData = json_decode($packageInfos, true);

        if (!isset($packageData['versions'])) {
            return [];
        }

        $versions = $packageData['versions'];
        $returnVersions = [];

        foreach ($versions as $info) {
            $version = $info['version'];

            if (Comparator::greaterThan($version, $from) && Comparator::lessThan($version, $to)) {
                $returnVersions[] = $version;
            }
        }

        return $returnVersions;
    }
}
