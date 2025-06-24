<?php

declare(strict_types=1);

namespace Whatsdiff\Analyzers;

use Illuminate\Support\Collection;
use Whatsdiff\Data\PackageChange;

interface AnalyzerInterface
{
    /**
     * Get the package manager type this analyzer handles.
     */
    public function getType(): PackageManagerType;

    /**
     * Analyze the lock file and extract package information.
     *
     * @return Collection<string, array{version: string, source?: string, dist?: string}>
     */
    public function analyze(string $lockFileContent): Collection;

    /**
     * Calculate differences between old and new package states.
     *
     * @param Collection<string, array{version: string, source?: string, dist?: string}> $oldPackages
     * @param Collection<string, array{version: string, source?: string, dist?: string}> $newPackages
     * @return Collection<int, PackageChange>
     */
    public function calculateDiff(Collection $oldPackages, Collection $newPackages): Collection;
}
