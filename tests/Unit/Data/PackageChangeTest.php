<?php

declare(strict_types=1);

use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Data\PackageChange;
use Whatsdiff\Enums\ChangeStatus;
use Whatsdiff\Enums\Semver;

describe('PackageChange', function () {
    test('creates updated package with semver data', function () {
        $change = PackageChange::updated(
            name: 'symfony/console',
            type: PackageManagerType::COMPOSER,
            fromVersion: 'v5.4.0',
            toVersion: 'v6.0.0',
            releaseCount: 5,
            semver: Semver::Major
        );

        expect($change->name)->toBe('symfony/console')
            ->and($change->type)->toBe(PackageManagerType::COMPOSER)
            ->and($change->from)->toBe('v5.4.0')
            ->and($change->to)->toBe('v6.0.0')
            ->and($change->status)->toBe(ChangeStatus::Updated)
            ->and($change->releaseCount)->toBe(5)
            ->and($change->semver)->toBe(Semver::Major);
    });

    test('creates downgraded package with semver data', function () {
        $change = PackageChange::downgraded(
            name: 'laravel/framework',
            type: PackageManagerType::COMPOSER,
            fromVersion: 'v9.0.0',
            toVersion: 'v8.0.0',
            releaseCount: 3,
            semver: Semver::Major
        );

        expect($change->semver)->toBe(Semver::Major)
            ->and($change->status)->toBe(ChangeStatus::Downgraded)
            ->and($change->from)->toBe('v9.0.0')
            ->and($change->to)->toBe('v8.0.0');
    });

    test('added packages have no semver data', function () {
        $change = PackageChange::added(
            name: 'new/package',
            type: PackageManagerType::COMPOSER,
            version: 'v1.0.0'
        );

        expect($change->semver)->toBeNull()
            ->and($change->from)->toBeNull()
            ->and($change->status)->toBe(ChangeStatus::Added);
    });

    test('removed packages have no semver data', function () {
        $change = PackageChange::removed(
            name: 'old/package',
            type: PackageManagerType::COMPOSER,
            version: 'v1.0.0'
        );

        expect($change->semver)->toBeNull()
            ->and($change->to)->toBeNull()
            ->and($change->status)->toBe(ChangeStatus::Removed);
    });

    test('updated package can have null semver for non-semver versions', function () {
        $change = PackageChange::updated(
            name: 'test/package',
            type: PackageManagerType::COMPOSER,
            fromVersion: 'dev-main',
            toVersion: 'dev-feature',
            releaseCount: null,
            semver: null
        );

        expect($change->semver)->toBeNull()
            ->and($change->status)->toBe(ChangeStatus::Updated);
    });

    test('downgraded package can have null semver for non-semver versions', function () {
        $change = PackageChange::downgraded(
            name: 'test/package',
            type: PackageManagerType::COMPOSER,
            fromVersion: 'dev-feature',
            toVersion: 'dev-main',
            releaseCount: null,
            semver: null
        );

        expect($change->semver)->toBeNull()
            ->and($change->status)->toBe(ChangeStatus::Downgraded);
    });

    test('supports different semver change types', function () {
        $majorChange = PackageChange::updated(
            name: 'test/package',
            type: PackageManagerType::COMPOSER,
            fromVersion: '1.0.0',
            toVersion: '2.0.0',
            semver: Semver::Major
        );

        $minorChange = PackageChange::updated(
            name: 'test/package',
            type: PackageManagerType::COMPOSER,
            fromVersion: '1.0.0',
            toVersion: '1.1.0',
            semver: Semver::Minor
        );

        $patchChange = PackageChange::updated(
            name: 'test/package',
            type: PackageManagerType::COMPOSER,
            fromVersion: '1.0.0',
            toVersion: '1.0.1',
            semver: Semver::Patch
        );

        expect($majorChange->semver)->toBe(Semver::Major)
            ->and($minorChange->semver)->toBe(Semver::Minor)
            ->and($patchChange->semver)->toBe(Semver::Patch);
    });

    test('supports NPM package manager type', function () {
        $change = PackageChange::updated(
            name: 'lodash',
            type: PackageManagerType::NPM,
            fromVersion: '4.0.0',
            toVersion: '4.1.0',
            semver: Semver::Minor
        );

        expect($change->type)->toBe(PackageManagerType::NPM)
            ->and($change->semver)->toBe(Semver::Minor);
    });
});
