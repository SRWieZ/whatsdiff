<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Data\DependencyDiff;
use Whatsdiff\Data\DiffResult;
use Whatsdiff\Data\PackageChange;

class JsonOutput implements OutputFormatterInterface
{
    public function format(DiffResult $result, OutputInterface $output): void
    {
        $data = [
            'has_uncommitted_changes' => $result->hasUncommittedChanges,
            'diffs' => $result->diffs->map(fn (DependencyDiff $diff) => [
                'filename' => $diff->filename,
                'type' => $diff->type,
                'from_commit' => $diff->fromCommit,
                'to_commit' => $diff->toCommit,
                'is_new' => $diff->isNew,
                'changes' => $diff->changes->map(fn (PackageChange $change) => [
                    'name' => $change->name,
                    'type' => $change->type,
                    'from' => $change->from,
                    'to' => $change->to,
                    'status' => $change->status->value,
                    'release_count' => $change->releaseCount,
                ])->toArray(),
            ])->toArray(),
        ];

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
