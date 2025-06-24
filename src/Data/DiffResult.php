<?php

declare(strict_types=1);

namespace Whatsdiff\Data;

use Illuminate\Support\Collection;

final readonly class DiffResult
{
    /**
     * @param Collection<int, DependencyDiff> $diffs
     */
    public function __construct(
        public Collection $diffs,
        public bool $hasUncommittedChanges = false,
    ) {
    }

    public function hasDiffs(): bool
    {
        return $this->diffs->isNotEmpty();
    }

    public function hasAnyChanges(): bool
    {
        return $this->diffs->some(fn (DependencyDiff $diff) => $diff->hasChanges());
    }

    public function getAllChanges(): Collection
    {
        return $this->diffs->flatMap(fn (DependencyDiff $diff) => $diff->changes);
    }
}
