<?php

declare(strict_types=1);

namespace Whatsdiff\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Whatsdiff\Application;

class HttpService
{
    private CacheService $cache;
    private Client $client;
    private array $lastResponseHeaders = [];

    public function __construct(CacheService $cache)
    {
        $this->cache = $cache;
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'allow_redirects' => [
                'max' => 5,
                'strict' => false,
                'referer' => false,
                'protocols' => ['http', 'https'],
            ],
            'http_errors' => false, // We'll handle errors ourselves
        ]);
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
        // Build Guzzle request options
        $guzzleOptions = [];

        // Set User-Agent
        $userAgent = $options['user_agent'] ?? 'whatsdiff/' . Application::getVersionString();
        $guzzleOptions['headers']['User-Agent'] = $userAgent;

        // Handle HTTP authentication if provided
        if (isset($options['auth'])) {
            $guzzleOptions['auth'] = [
                $options['auth']['username'],
                $options['auth']['password'],
            ];
        }

        // Handle custom headers
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $key => $value) {
                $guzzleOptions['headers'][$key] = $value;
            }
        }

        try {
            $response = $this->client->get($url, $guzzleOptions);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                throw new RuntimeException("HTTP request failed with status code: {$statusCode}");
            }
            throw new RuntimeException('Failed to fetch URL: ' . $e->getMessage());
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to fetch URL: ' . $e->getMessage());
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new RuntimeException("HTTP request failed with status code: {$statusCode}");
        }

        // Get response body
        $body = (string) $response->getBody();

        // Extract and parse headers
        $this->lastResponseHeaders = $this->parseGuzzleHeaders($response);

        // Update cache duration based on headers
        $cacheDuration = $this->cache->getCacheDuration($this->lastResponseHeaders);
        if ($cacheDuration > 0) {
            $cacheKey = 'http_' . $url;
            $this->cache->set($cacheKey, $body, $cacheDuration);
        }

        return $body;
    }

    private function parseGuzzleHeaders(ResponseInterface $response): array
    {
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $name = strtolower($name);
            if (count($values) === 1) {
                $headers[$name] = $values[0];
            } else {
                $headers[$name] = $values;
            }
        }

        return $headers;
    }

}
