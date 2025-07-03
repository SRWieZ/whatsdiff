<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\Comparator;
use Whatsdiff\Analyzers\PackageManagerType;

class PackageInfoFetcher
{
    private HttpService $httpService;

    public function __construct(HttpService $httpService)
    {
        $this->httpService = $httpService;
    }
    public function getComposerReleases(string $package, string $from, string $to, string $url): array
    {
        try {
            // Extract authentication from URL if present
            $options = $this->extractAuthFromUrl($url);
            $cleanUrl = $options['url'];

            $packageInfos = $this->httpService->get($cleanUrl, $options['options']);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to fetch package information for {$package}: " . $e->getMessage());
        }

        $packageData = json_decode($packageInfos, true);

        if (!isset($packageData['packages'][$package])) {
            return [];
        }

        $versions = $packageData['packages'][$package];
        $returnVersions = [];

        foreach ($versions as $info) {
            $version = $info['version'];

            if (Comparator::greaterThan($version, $from) && Comparator::lessThanOrEqualTo($version, $to)) {
                $returnVersions[] = $version;
            }
        }

        return $returnVersions;
    }

    public function getNpmReleases(string $package, string $from, string $to): array
    {
        $url = PackageManagerType::NPM->getRegistryUrl($package);

        try {
            $packageInfos = $this->httpService->get($url);
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to fetch package information for {$package}: " . $e->getMessage());
        }

        $packageData = json_decode($packageInfos, true);

        if (!isset($packageData['versions'])) {
            return [];
        }

        $versions = $packageData['versions'];
        $returnVersions = [];

        foreach ($versions as $info) {
            $version = $info['version'];

            if (Comparator::greaterThan($version, $from) && Comparator::lessThanOrEqualTo($version, $to)) {
                $returnVersions[] = $version;
            }
        }

        return $returnVersions;
    }

    private function extractAuthFromUrl(string $url): array
    {
        $parsedUrl = parse_url($url);
        $options = [];

        if (isset($parsedUrl['user']) && isset($parsedUrl['pass'])) {
            $options['auth'] = [
                'username' => urldecode($parsedUrl['user']),
                'password' => urldecode($parsedUrl['pass']),
            ];

            // Rebuild URL without auth
            $cleanUrl = $parsedUrl['scheme'] . '://';
            $cleanUrl .= $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $cleanUrl .= ':' . $parsedUrl['port'];
            }
            $cleanUrl .= $parsedUrl['path'] ?? '';
            if (isset($parsedUrl['query'])) {
                $cleanUrl .= '?' . $parsedUrl['query'];
            }
            if (isset($parsedUrl['fragment'])) {
                $cleanUrl .= '#' . $parsedUrl['fragment'];
            }

            return ['url' => $cleanUrl, 'options' => $options];
        }

        return ['url' => $url, 'options' => []];
    }
}
