<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ProcessService
{
    private ExecutableFinder $executableFinder;

    public function __construct()
    {
        $this->executableFinder = new ExecutableFinder();
    }

    public function run(array $command, ?string $cwd = null, ?int $timeout = 60): Process
    {
        $process = new Process($command, $cwd, null, null, $timeout);
        $process->run();

        return $process;
    }

    public function git(array $gitArgs, ?string $cwd = null): Process
    {
        $command = array_merge(['git'], $gitArgs);
        return $this->run($command, $cwd);
    }

    public function php(array $phpArgs, ?string $cwd = null): Process
    {
        $phpBinary = $this->executableFinder->find('php');

        if (!$phpBinary) {
            throw new \RuntimeException('PHP executable not found');
        }

        $command = array_merge([$phpBinary], $phpArgs);
        return $this->run($command, $cwd);
    }
}
