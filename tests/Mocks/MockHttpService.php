<?php

declare(strict_types=1);

namespace Tests\Mocks;

use Whatsdiff\Services\HttpService;

class MockHttpService extends HttpService
{
    public array $responses = [];
    public array $capturedOptions = [];
    public ?string $lastRequestUrl = null;

    public function __construct()
    {
        // Skip parent constructor to avoid creating real Guzzle client
    }

    public function setResponse(string $url, string $response): void
    {
        $this->responses[$url] = $response;
    }

    public function get(string $url, array $options = []): string
    {
        $this->capturedOptions = $options; // Capture the auth options
        $this->lastRequestUrl = $url; // Track which URL was called

        if (!isset($this->responses[$url])) {
            // Simulate authentication failure for private packages
            if (str_contains($url, 'flux-pro')) {
                throw new \Exception('HTTP 401 Unauthorized');
            }
            return '{"packages":{}}'; // Default empty response for public packages
        }

        return $this->responses[$url];
    }

    public function getWithHeaders(string $url, array $options = []): array
    {
        return [
            'content' => $this->get($url, $options),
            'headers' => []
        ];
    }
}
