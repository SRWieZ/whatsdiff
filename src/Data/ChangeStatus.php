<?php

declare(strict_types=1);

namespace Whatsdiff\Data;

enum ChangeStatus: string
{
    case Added = 'added';
    case Removed = 'removed';
    case Updated = 'updated';
    case Downgraded = 'downgraded';
}
