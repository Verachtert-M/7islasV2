<?php

namespace App\Http;

/**
 * HTTP Request handler for form submissions
 */
class Request
{
    private array $data;
    private array $files;
    private array $headers;
    private string $method;
    private string $uri;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->headers = $this->parseHeaders();
        $this->data = $this->parseData();
        $this->files = $_FILES ?? [];
    }

    /**
     * Get request method
     */
    public function method(): string
    {
        return $this->method;
    }

    /**
     * Get request URI
     */
    public function uri(): string
    {
        return $this->uri;
    }

    /**
     * Get all request data
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Get specific input value
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * Check if input exists
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Get only specified keys
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /**
     * Get all except specified keys
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    /**
     * Get uploaded files
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get all uploaded files
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Get request headers
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Get specific header
     */
    public function header(string $key, mixed $default = null): mixed
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * Check if request is POST
     */
    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * Check if request is GET
     */
    public function isGet(): bool
    {
        return $this->method === 'GET';
    }

    /**
     * Check if request expects JSON response
     */
    public function expectsJson(): bool
    {
        $accept = $this->header('accept', '');
        return str_contains($accept, 'application/json') ||
            str_contains($accept, 'text/json') ||
            $this->header('content-type') === 'application/json';
    }

    /**
     * Get client IP address
     */
    public function ip(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['HTTP_X_REAL_IP'] ??
            $_SERVER['REMOTE_ADDR'] ??
            'unknown';
    }

    /**
     * Get user agent
     */
    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Validate CSRF token (if implemented)
     */
    public function validateCsrf(string $token): bool
    {
        $requestToken = $this->input('_token') ?? $this->header('x-csrf-token');
        return hash_equals($token, $requestToken ?? '');
    }

    /**
     * Parse request data based on content type
     */
    private function parseData(): array
    {
        $contentType = $this->header('content-type', '');

        if (str_contains($contentType, 'application/json')) {
            $json = file_get_contents('php://input');
            return json_decode($json, true) ?? [];
        }

        if ($this->method === 'POST') {
            return $_POST;
        }

        return $_GET;
    }

    /**
     * Parse HTTP headers
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        // Add content type and length if available
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
