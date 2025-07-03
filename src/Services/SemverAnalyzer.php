<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Composer\Semver\VersionParser;
use Whatsdiff\Enums\Semver;

final class SemverAnalyzer
{
    private readonly VersionParser $versionParser;

    public function __construct()
    {
        $this->versionParser = new VersionParser();
    }

    public function determineSemverChangeType(string $fromVersion, string $toVersion): ?Semver
    {
        // Check for dev versions that shouldn't be analyzed
        if ($this->isDevVersion($fromVersion) || $this->isDevVersion($toVersion)) {
            return null;
        }

        $fromParts = $this->parseVersion($fromVersion);
        $toParts = $this->parseVersion($toVersion);

        if ($fromParts === null || $toParts === null) {
            return null;
        }

        if ($fromParts['major'] !== $toParts['major']) {
            return Semver::Major;
        }

        if ($fromParts['minor'] !== $toParts['minor']) {
            return Semver::Minor;
        }

        if ($fromParts['patch'] !== $toParts['patch']) {
            return Semver::Patch;
        }

        return null;
    }

    private function parseVersion(string $version): ?array
    {
        try {
            $normalized = $this->versionParser->normalize($version);
        } catch (\UnexpectedValueException) {
            return null;
        }

        if (! preg_match('/^(\d+)\.(\d+)\.(\d+)(?:\.(\d+))?(?:-(.+))?(?:\+(.+))?$/', $normalized, $matches)) {
            return null;
        }

        return [
            'major' => (int) $matches[1],
            'minor' => (int) $matches[2],
            'patch' => (int) $matches[3],
        ];
    }

    private function isDevVersion(string $version): bool
    {
        return $this->versionParser->parseStability($version) === 'dev';
    }
}
