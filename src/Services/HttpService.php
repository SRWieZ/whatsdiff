<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

class HttpService
{
    private CacheService $cache;
    private array $lastResponseHeaders = [];

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $url, array $options = []): string
    {
        $cacheKey = 'http_' . $url;

        return $this->cache->get($cacheKey, function () use ($url, $options) {
            return $this->fetchUrl($url, $options);
        });
    }

    public function getWithHeaders(string $url, array $options = []): array
    {
        $cacheKey = 'http_with_headers_' . $url;

        return $this->cache->get($cacheKey, function () use ($url, $options) {
            $content = $this->fetchUrl($url, $options);
            return [
                'body' => $content,
                'headers' => $this->lastResponseHeaders,
            ];
        });
    }

    public function getResponseHeaders(): array
    {
        return $this->lastResponseHeaders;
    }

    private function fetchUrl(string $url, array $options = []): string
    {
        $ch = curl_init();

        // Set basic options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);

        // Set User-Agent
        $userAgent = $options['user_agent'] ?? 'whatsdiff/' . $this->getVersion();
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);

        // Handle HTTP authentication if provided
        if (isset($options['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, $options['auth']['username'] . ':' . $options['auth']['password']);
        }

        // Handle custom headers
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = $key . ': ' . $value;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Execute request
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Failed to fetch URL: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP request failed with status code: {$httpCode}");
        }

        // Separate headers and body
        $headerString = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Parse headers
        $this->lastResponseHeaders = $this->parseHeaders($headerString);

        // Update cache duration based on headers
        $cacheDuration = $this->cache->getCacheDuration($this->lastResponseHeaders);
        if ($cacheDuration > 0) {
            $cacheKey = 'http_' . $url;
            $this->cache->set($cacheKey, $body, $cacheDuration);
        }

        return $body;
    }

    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);

        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $key = strtolower(trim($key));
                $value = trim($value);

                if (isset($headers[$key])) {
                    if (!is_array($headers[$key])) {
                        $headers[$key] = [$headers[$key]];
                    }
                    $headers[$key][] = $value;
                } else {
                    $headers[$key] = $value;
                }
            }
        }

        return $headers;
    }

    private function getVersion(): string
    {
        // Try to get version from Application class constant
        if (defined('\\Whatsdiff\\Application::VERSION')) {
            $version = constant('\\Whatsdiff\\Application::VERSION');
            if (!str_starts_with($version, '@git_tag')) {
                return $version;
            }
        }

        return 'dev';
    }
}
