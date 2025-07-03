<?php

declare(strict_types=1);

namespace Tests\Mocks;

use Whatsdiff\Container\Container;
use Whatsdiff\Container\ServiceProviderInterface;
use Whatsdiff\Container\WhatsdiffServiceProvider;
use Whatsdiff\Services\HttpService;

class TestServiceProvider implements ServiceProviderInterface
{
    private HttpService $mockHttpService;

    public function __construct(HttpService $mockHttpService)
    {
        $this->mockHttpService = $mockHttpService;
    }

    public function register(Container $container): void
    {
        // Register all the default services first
        $defaultProvider = new WhatsdiffServiceProvider();
        $defaultProvider->register($container);

        // Override only the HttpService with our mock
        $container->singleton(HttpService::class, fn () => $this->mockHttpService);
    }
}
