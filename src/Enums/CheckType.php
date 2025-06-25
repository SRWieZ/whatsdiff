<?php

declare(strict_types=1);

namespace Whatsdiff\Enums;

enum CheckType: string
{
    case Any = 'any';
    case Updated = 'updated';
    case Downgraded = 'downgraded';
    case Removed = 'removed';
    case Added = 'added';
}
