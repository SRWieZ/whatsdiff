<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Whatsdiff\Container\Container;
use Whatsdiff\Container\Exceptions\ContainerException;
use Whatsdiff\Container\Exceptions\NotFoundException;

describe('PSR-11 Compliance', function () {
    it('implements ContainerInterface', function () {
        $container = new Container();

        expect($container)->toBeInstanceOf(ContainerInterface::class);
    });

    it('has method gets service by identifier', function () {
        $container = new Container();
        $service = new stdClass();
        $container->set('test.service', $service);

        $result = $container->get('test.service');

        expect($result)->toBe($service);
    });

    it('has method returns true for existing services', function () {
        $container = new Container();
        $container->set('test.service', new stdClass());

        expect($container->has('test.service'))->toBeTrue();
    });

    it('has method returns false for non-existing services', function () {
        $container = new Container();

        expect($container->has('non.existing.service'))->toBeFalse();
    });

    it('throws NotFoundExceptionInterface for non-existing services', function () {
        $container = new Container();

        try {
            $container->get('non.existing.service');
            expect(false)->toBeTrue('Expected NotFoundExceptionInterface to be thrown');
        } catch (Exception $e) {
            expect($e)->toBeInstanceOf(NotFoundExceptionInterface::class);
        }
    });

    it('throws specific NotFoundException for non-existing services', function () {
        $container = new Container();

        try {
            $container->get('non.existing.service');
            expect(false)->toBeTrue('Expected NotFoundException to be thrown');
        } catch (Exception $e) {
            expect($e)->toBeInstanceOf(NotFoundException::class);
        }
    });

    it('supports callable service factories', function () {
        $container = new Container();
        $container->set('test.service', fn () => new stdClass());

        $result = $container->get('test.service');

        expect($result)->toBeInstanceOf(stdClass::class);
    });

    it('supports singleton services', function () {
        $container = new Container();
        $container->singleton('test.singleton', fn () => new stdClass());

        $first = $container->get('test.singleton');
        $second = $container->get('test.singleton');

        expect($first)->toBe($second);
    });

    it('injects container into factory functions', function () {
        $container = new Container();
        $container->set('dependency', new stdClass());
        $container->set('test.service', fn (ContainerInterface $c) => $c->get('dependency'));

        $result = $container->get('test.service');

        expect($result)->toBeInstanceOf(stdClass::class);
    });

    it('throws ContainerException on factory errors', function () {
        $container = new Container();
        $container->set('failing.service', fn () => throw new RuntimeException('Factory error'));

        try {
            $container->get('failing.service');
            expect(false)->toBeTrue('Expected ContainerException to be thrown');
        } catch (Exception $e) {
            expect($e)->toBeInstanceOf(ContainerException::class);
        }
    });
});
