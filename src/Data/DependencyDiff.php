<?php

declare(strict_types=1);

namespace Whatsdiff\Data;

use Illuminate\Support\Collection;
use Whatsdiff\Analyzers\PackageManagerType;
use Whatsdiff\Enums\ChangeStatus;

final readonly class DependencyDiff
{
    /**
     * @param Collection<int, PackageChange> $changes
     */
    public function __construct(
        public string $filename,
        public PackageManagerType $type,
        public ?string $fromCommit,
        public ?string $toCommit,
        public Collection $changes,
        public bool $isNew = false,
    ) {
    }

    public function hasChanges(): bool
    {
        return $this->changes->isNotEmpty();
    }

    public function getAddedPackages(): Collection
    {
        return $this->changes->filter(fn (PackageChange $change) => $change->status === ChangeStatus::Added);
    }

    public function getRemovedPackages(): Collection
    {
        return $this->changes->filter(fn (PackageChange $change) => $change->status === ChangeStatus::Removed);
    }

    public function getUpdatedPackages(): Collection
    {
        return $this->changes->filter(fn (PackageChange $change) => $change->status === ChangeStatus::Updated);
    }

    public function getDowngradedPackages(): Collection
    {
        return $this->changes->filter(fn (PackageChange $change) => $change->status === ChangeStatus::Downgraded);
    }
}
