<?php

declare(strict_types=1);

namespace Whatsdiff\Analyzers;

enum PackageManagerType: string
{
    case COMPOSER = 'composer';
    case NPM = 'npmjs';

    public function getLabel(): string
    {
        return match ($this) {
            self::COMPOSER => 'Composer',
            self::NPM => 'npm',
        };
    }

    public function getLockFileName(): string
    {
        return match ($this) {
            self::COMPOSER => 'composer.lock',
            self::NPM => 'package-lock.json',
        };
    }
}
