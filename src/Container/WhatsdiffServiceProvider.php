<?php

declare(strict_types=1);

namespace Whatsdiff\Container;

use Whatsdiff\Analyzers\ComposerAnalyzer;
use Whatsdiff\Analyzers\NpmAnalyzer;
use Whatsdiff\Outputs\JsonOutput;
use Whatsdiff\Outputs\MarkdownOutput;
use Whatsdiff\Outputs\OutputFormatterInterface;
use Whatsdiff\Outputs\TextOutput;
use Whatsdiff\Services\CacheService;
use Whatsdiff\Services\ConfigService;
use Whatsdiff\Services\DiffCalculator;
use Whatsdiff\Services\GitRepository;
use Whatsdiff\Services\HttpService;
use Whatsdiff\Services\PackageInfoFetcher;

class WhatsdiffServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        // Register configuration service as singleton
        $container->singleton(ConfigService::class, function () {
            return new ConfigService();
        });

        // Register cache service as singleton
        $container->singleton(CacheService::class, function (Container $container) {
            return new CacheService($container->get(ConfigService::class));
        });

        // Register HTTP service as singleton
        $container->singleton(HttpService::class, function (Container $container) {
            return new HttpService($container->get(CacheService::class));
        });

        // Register Git repository service as singleton
        $container->singleton(GitRepository::class, function () {
            return new GitRepository();
        });

        // Register package info fetcher as singleton
        $container->singleton(PackageInfoFetcher::class, function (Container $container) {
            return new PackageInfoFetcher($container->get(HttpService::class));
        });

        // Register analyzers as singletons
        $container->singleton(ComposerAnalyzer::class, function (Container $container) {
            return new ComposerAnalyzer($container->get(PackageInfoFetcher::class));
        });

        $container->singleton(NpmAnalyzer::class, function (Container $container) {
            return new NpmAnalyzer($container->get(PackageInfoFetcher::class));
        });

        // Register diff calculator - not singleton as it might have different configurations
        $container->set(DiffCalculator::class, function (Container $container) {
            return new DiffCalculator(
                $container->get(GitRepository::class),
                $container->get(ComposerAnalyzer::class),
                $container->get(NpmAnalyzer::class)
            );
        });

        // Register output formatters
        $container->set('formatter.text', function (Container $container) {
            return function (bool $withAnsi = true): TextOutput {
                return new TextOutput($withAnsi);
            };
        });

        $container->set('formatter.json', function () {
            return new JsonOutput();
        });

        $container->set('formatter.markdown', function () {
            return new MarkdownOutput();
        });

        // Register formatter factory
        $container->set('formatter.factory', function (Container $containerInstance) {
            return function (string $format, bool $withAnsi = true) use ($containerInstance): OutputFormatterInterface {
                return match ($format) {
                    'json' => $containerInstance->get('formatter.json'),
                    'markdown' => $containerInstance->get('formatter.markdown'),
                    default => $containerInstance->get('formatter.text')($withAnsi),
                };
            };
        });
    }
}
