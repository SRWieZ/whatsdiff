<?php

declare(strict_types=1);

namespace Whatsdiff\Container;

use Psr\Container\ContainerInterface;
use Whatsdiff\Container\Exceptions\ContainerException;
use Whatsdiff\Container\Exceptions\NotFoundException;

class Container implements ContainerInterface
{
    private array $services = [];
    private array $instances = [];
    private array $singletons = [];

    /**
     * Register a service with the container
     */
    public function set(string $id, mixed $concrete, bool $singleton = false): void
    {
        $this->services[$id] = $concrete;

        if ($singleton) {
            $this->singletons[$id] = true;
        }
    }

    /**
     * Register a singleton service with the container
     */
    public function singleton(string $id, mixed $concrete): void
    {
        $this->set($id, $concrete, true);
    }

    /**
     * Get a service from the container
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new NotFoundException("Service '{$id}' not found in container");
        }

        // Return existing singleton instance if available
        if (isset($this->singletons[$id]) && isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        try {
            $service = $this->services[$id];

            // If service is a callable, invoke it
            if (is_callable($service)) {
                $instance = $service($this);
            } else {
                $instance = $service;
            }

            // Store singleton instances
            if (isset($this->singletons[$id])) {
                $this->instances[$id] = $instance;
            }

            return $instance;

        } catch (\Throwable $e) {
            throw new ContainerException("Error resolving service '{$id}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Check if a service exists in the container
     */
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }

    /**
     * Register a service provider with the container
     */
    public function register(ServiceProviderInterface $provider): void
    {
        $provider->register($this);
    }
}
