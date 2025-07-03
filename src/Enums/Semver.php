<?php

declare(strict_types=1);

namespace Whatsdiff\Enums;

enum Semver: string
{
    case Major = 'major';
    case Minor = 'minor';
    case Patch = 'patch';
}
