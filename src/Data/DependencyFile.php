<?php

declare(strict_types=1);

namespace Whatsdiff\Data;

use Whatsdiff\Analyzers\PackageManagerType;

final readonly class DependencyFile
{
    /**
     * @param array<string> $commitLogs
     */
    public function __construct(
        public string $file,
        public PackageManagerType $type,
        public bool $hasBeenRecentlyUpdated,
        public bool $hasCommitLogs,
        public array $commitLogs,
    ) {
    }

    public static function create(PackageManagerType $type, string $file): self
    {
        return new self(
            file: $file,
            type: $type,
            hasBeenRecentlyUpdated: false,
            hasCommitLogs: false,
            commitLogs: [],
        );
    }

    public function withUpdatedStatus(
        bool $hasBeenRecentlyUpdated,
        bool $hasCommitLogs,
        array $commitLogs
    ): self {
        return new self(
            file: $this->file,
            type: $this->type,
            hasBeenRecentlyUpdated: $hasBeenRecentlyUpdated,
            hasCommitLogs: $hasCommitLogs,
            commitLogs: $commitLogs,
        );
    }

    public function withFile(string $file): self
    {
        return new self(
            file: $file,
            type: $this->type,
            hasBeenRecentlyUpdated: $this->hasBeenRecentlyUpdated,
            hasCommitLogs: $this->hasCommitLogs,
            commitLogs: $this->commitLogs,
        );
    }
}
