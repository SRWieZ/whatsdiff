<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class ConfigService
{
    private Filesystem $filesystem;
    private string $configPath;
    private array $config = [];
    private array $defaults = [
        'cache' => [
            'enabled' => true,
            'min-time' => 300, // 5 minutes
            'max-time' => 86400, // 1 day
        ],
    ];

    public function __construct(?string $configPath = null)
    {
        $this->filesystem = new Filesystem();
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
        $this->loadConfig();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default ?? $this->getDefault($key);
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        $this->saveConfig();
    }

    public function getAll(): array
    {
        return array_replace_recursive($this->defaults, $this->config);
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    private function getDefaultConfigPath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (!$home) {
            throw new \RuntimeException('Cannot determine home directory');
        }

        return $home . DIRECTORY_SEPARATOR . '.whatsdiff' . DIRECTORY_SEPARATOR . 'config.yaml';
    }

    private function loadConfig(): void
    {
        $this->ensureConfigExists();

        if ($this->filesystem->exists($this->configPath)) {
            $content = file_get_contents($this->configPath);
            if ($content !== false) {
                $this->config = Yaml::parse($content) ?: [];
            }
        }
    }

    private function saveConfig(): void
    {
        $this->ensureConfigExists();

        $yaml = Yaml::dump($this->config, 4, 2);
        $this->filesystem->dumpFile($this->configPath, $yaml);
    }

    private function ensureConfigExists(): void
    {
        $dir = dirname($this->configPath);
        if (!$this->filesystem->exists($dir)) {
            $this->filesystem->mkdir($dir, 0755);
        }

        if (!$this->filesystem->exists($this->configPath)) {
            $this->filesystem->dumpFile($this->configPath, Yaml::dump($this->defaults, 4, 2));
        }
    }

    private function getDefault(string $key): mixed
    {
        $keys = explode('.', $key);
        $value = $this->defaults;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return null;
            }
            $value = $value[$k];
        }

        return $value;
    }
}
