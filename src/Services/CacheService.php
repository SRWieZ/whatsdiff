<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;

class CacheService
{
    private CacheItemPoolInterface $cache;
    private ConfigService $config;
    private Filesystem $filesystem;
    private bool $forceDisabled = false;

    public function __construct(ConfigService $config, ?string $cacheDir = null)
    {
        $this->config = $config;
        $this->filesystem = new Filesystem();

        $cacheDirectory = $cacheDir ?? $this->getDefaultCacheDir();
        $this->ensureCacheDirectoryExists($cacheDirectory);

        $this->cache = new FilesystemAdapter(
            'whatsdiff',
            0,
            $cacheDirectory
        );
    }

    public function get(string $key, callable $callback): mixed
    {
        if (!$this->isCacheEnabled()) {
            return $callback();
        }

        $item = $this->cache->getItem($this->sanitizeKey($key));

        if ($item->isHit()) {
            return $item->get();
        }

        $value = $callback();

        if ($value !== null) {
            $item->set($value);
            $item->expiresAfter($this->getCacheDuration());
            $this->cache->save($item);
        }

        return $value;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if (!$this->isCacheEnabled()) {
            return;
        }

        $item = $this->cache->getItem($this->sanitizeKey($key));
        $item->set($value);

        $duration = $ttl ?? $this->getCacheDuration();
        $item->expiresAfter($duration);

        $this->cache->save($item);
    }

    public function delete(string $key): void
    {
        $this->cache->deleteItem($this->sanitizeKey($key));
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    public function isCacheEnabled(): bool
    {
        if ($this->forceDisabled) {
            return false;
        }

        return (bool) $this->config->get('cache.enabled', true);
    }

    public function disableCache(): void
    {
        $this->forceDisabled = true;
    }

    public function enableCache(): void
    {
        $this->forceDisabled = false;
    }

    public function getCacheDuration(?array $headers = null): int
    {
        $minTime = (int) $this->config->get('cache.min-time', 300);
        $maxTime = (int) $this->config->get('cache.max-time', 86400);

        if ($headers !== null) {
            $duration = $this->parseCacheHeaders($headers);
            if ($duration !== null) {
                return max($minTime, min($duration, $maxTime));
            }
        }

        // Default to min time if no headers or unable to parse
        return $minTime;
    }

    private function parseCacheHeaders(array $headers): ?int
    {
        // Check Cache-Control header
        if (isset($headers['cache-control'])) {
            $cacheControl = is_array($headers['cache-control'])
                ? $headers['cache-control'][0]
                : $headers['cache-control'];

            if (preg_match('/max-age=(\d+)/', $cacheControl, $matches)) {
                return (int) $matches[1];
            }

            if (stripos($cacheControl, 'no-cache') !== false || stripos($cacheControl, 'no-store') !== false) {
                return 0;
            }
        }

        // Check Expires header
        if (isset($headers['expires'])) {
            $expires = is_array($headers['expires'])
                ? $headers['expires'][0]
                : $headers['expires'];

            $expiresTime = strtotime($expires);
            if ($expiresTime !== false && $expiresTime > time()) {
                return $expiresTime - time();
            }
        }

        return null;
    }

    private function sanitizeKey(string $key): string
    {
        // PSR-6 requires keys to only contain A-Z, a-z, 0-9, _, and .
        return preg_replace('/[^A-Za-z0-9_.]+/', '_', $key) ?? $key;
    }

    private function getDefaultCacheDir(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (!$home) {
            throw new \RuntimeException('Cannot determine home directory');
        }

        return $home . DIRECTORY_SEPARATOR . '.whatsdiff' . DIRECTORY_SEPARATOR . 'cache';
    }

    private function ensureCacheDirectoryExists(string $directory): void
    {
        if (!$this->filesystem->exists($directory)) {
            $this->filesystem->mkdir($directory, 0755);
        }
    }
}
