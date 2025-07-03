<?php

declare(strict_types=1);

namespace Whatsdiff;

use Symfony\Component\Console\Application as BaseApplication;
use Whatsdiff\Commands\BetweenCommand;
use Whatsdiff\Commands\CheckCommand;
use Whatsdiff\Commands\ConfigCommand;
use Whatsdiff\Commands\AnalyseCommand;
use Whatsdiff\Commands\TuiCommand;
use Whatsdiff\Container\Container;
use Whatsdiff\Container\WhatsdiffServiceProvider;

class Application extends BaseApplication
{
    private const VERSION = '@git_tag@';

    private Container $container;

    public function __construct()
    {
        // Set up error handling
        if (class_exists('\NunoMaduro\Collision\Provider')) {
            (new \NunoMaduro\Collision\Provider())->register();
        } else {
            error_reporting(0);
        }

        parent::__construct('whatsdiff', self::getVersionString());

        // Initialize container and register services
        $this->container = new Container();
        $this->container->register(new WhatsdiffServiceProvider());

        $this->add(new AnalyseCommand($this->container));
        $this->add(new BetweenCommand($this->container));
        $this->add(new TuiCommand($this->container));
        $this->add(new CheckCommand($this->container));
        $this->add(new ConfigCommand($this->container));
        $this->setDefaultCommand('analyse');
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getLongVersion(): string
    {
        $version = parent::getLongVersion();

        $version .= PHP_EOL.PHP_EOL;
        $version .= 'PHP version: '.phpversion().PHP_EOL;

        if (self::getVersion() !== 'dev') {
            $version .= 'Built with https://github.com/box-project/box'.PHP_EOL;
        }

        if (php_sapi_name() === 'micro') {
            $version .= 'Compiled with https://github.com/crazywhalecc/static-php-cli'.PHP_EOL;
        }

        return $version;
    }

    public static function getVersionString(): string
    {
        if (! str_starts_with(self::VERSION, '@git_tag')) {
            return self::VERSION;
        }

        return 'dev';
    }
}
