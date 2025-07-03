<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Whatsdiff\Container\Container;

describe('PSR-11 Interoperability', function () {
    it('can be used as ContainerInterface type hint', function () {
        $container = new Container();
        $container->set('test.service', new stdClass());

        $result = useContainerInterface($container);

        expect($result)->toBeInstanceOf(stdClass::class);
    });

    it('works with dependency injection patterns', function () {
        $container = new Container();

        // Register a service that depends on another
        $container->set('logger', fn () => new class () {
            public function log(string $message): string
            {
                return "Logged: $message";
            }
        });

        $container->set('service', fn (ContainerInterface $c) => new class ($c->get('logger')) {
            public function __construct(private object $logger)
            {
            }

            public function doSomething(): string
            {
                return $this->logger->log('Something happened');
            }
        });

        $service = $container->get('service');
        $result = $service->doSomething();

        expect($result)->toBe('Logged: Something happened');
    });

    it('supports PSR-11 container composition', function () {
        $container = new Container();
        $container->set('name', 'whatsdiff');

        // Another service that accepts a PSR-11 container
        $service = new class ($container) {
            public function __construct(private ContainerInterface $container)
            {
            }

            public function getAppName(): string
            {
                return $this->container->get('name');
            }
        };

        expect($service->getAppName())->toBe('whatsdiff');
    });
});

/**
 * Helper function that accepts any PSR-11 container
 */
function useContainerInterface(ContainerInterface $container): object
{
    return $container->get('test.service');
}
