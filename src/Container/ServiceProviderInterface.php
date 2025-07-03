<?php

declare(strict_types=1);

namespace Whatsdiff\Container;

interface ServiceProviderInterface
{
    /**
     * Register services with the container
     */
    public function register(Container $container): void;
}
