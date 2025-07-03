<?php

declare(strict_types=1);

use Whatsdiff\Enums\Semver;
use Whatsdiff\Services\SemverAnalyzer;

beforeEach(function () {
    $this->analyzer = new SemverAnalyzer();
});

describe('SemverAnalyzer', function () {
    test('determines major version change', function () {
        $result = $this->analyzer->determineSemverChangeType('1.0.0', '2.0.0');
        expect($result)->toBe(Semver::Major);
    });

    test('determines minor version change', function () {
        $result = $this->analyzer->determineSemverChangeType('1.0.0', '1.1.0');
        expect($result)->toBe(Semver::Minor);
    });

    test('determines patch version change', function () {
        $result = $this->analyzer->determineSemverChangeType('1.0.0', '1.0.1');
        expect($result)->toBe(Semver::Patch);
    });

    test('handles downgrade scenarios', function () {
        expect($this->analyzer->determineSemverChangeType('2.0.0', '1.0.0'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('1.1.0', '1.0.0'))->toBe(Semver::Minor);
        expect($this->analyzer->determineSemverChangeType('1.0.1', '1.0.0'))->toBe(Semver::Patch);
    });

    test('handles complex version strings', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.0', '2.0.0-beta.1'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('1.0.0', '1.1.0-alpha'))->toBe(Semver::Minor);
        expect($this->analyzer->determineSemverChangeType('1.0.0', '1.0.1-rc.1'))->toBe(Semver::Patch);
    });

    test('handles version prefixes', function () {
        expect($this->analyzer->determineSemverChangeType('v1.0.0', 'v2.0.0'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('v1.0.0', 'v1.1.0'))->toBe(Semver::Minor);
        expect($this->analyzer->determineSemverChangeType('v1.0.0', 'v1.0.1'))->toBe(Semver::Patch);
    });

    test('handles four-digit versions', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.0.0', '2.0.0.0'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('1.0.0.0', '1.1.0.0'))->toBe(Semver::Minor);
        expect($this->analyzer->determineSemverChangeType('1.0.0.0', '1.0.1.0'))->toBe(Semver::Patch);
    });

    test('returns null for identical versions', function () {
        $result = $this->analyzer->determineSemverChangeType('1.0.0', '1.0.0');
        expect($result)->toBeNull();
    });

    test('returns null for invalid versions', function () {
        expect($this->analyzer->determineSemverChangeType('invalid', '1.0.0'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0', 'invalid'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('invalid', 'invalid'))->toBeNull();
    });

    test('returns null for non-semver versions', function () {
        expect($this->analyzer->determineSemverChangeType('dev-main', '1.0.0'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0', 'dev-main'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.x-dev', '2.x-dev'))->toBeNull();
    });

    test('handles composer branch aliases', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.x-dev', '1.1.x-dev'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('dev-master', 'dev-main'))->toBeNull();
    });

    test('handles pre-release versions correctly', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.0-alpha', '1.0.0'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0-alpha.1', '1.0.0-beta.1'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0-rc.1', '1.0.0'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0-alpha', '2.0.0'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('1.0.0', '1.1.0-beta'))->toBe(Semver::Minor);
    });

    test('handles build metadata', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.0+build.1', '1.0.0+build.2'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0', '1.0.0+build.1'))->toBeNull();
        expect($this->analyzer->determineSemverChangeType('1.0.0+build.1', '1.0.1+build.1'))->toBe(Semver::Patch);
    });

    test('prioritizes highest level change', function () {
        expect($this->analyzer->determineSemverChangeType('1.0.0', '2.1.1'))->toBe(Semver::Major);
        expect($this->analyzer->determineSemverChangeType('1.0.0', '1.1.1'))->toBe(Semver::Minor);
    });

    test('handles edge case versions', function () {
        expect($this->analyzer->determineSemverChangeType('0.0.1', '0.0.2'))->toBe(Semver::Patch);
        expect($this->analyzer->determineSemverChangeType('0.1.0', '0.2.0'))->toBe(Semver::Minor);
        expect($this->analyzer->determineSemverChangeType('0.1.0', '1.0.0'))->toBe(Semver::Major);
    });
});
