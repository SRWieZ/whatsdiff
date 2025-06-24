<?php

declare(strict_types=1);

namespace Whatsdiff\Outputs;

use Symfony\Component\Console\Output\OutputInterface;
use Whatsdiff\Data\DiffResult;

interface OutputFormatterInterface
{
    public function format(DiffResult $result, OutputInterface $output): void;
}
