<?php

namespace App\Http;

/**
 * HTTP Response handler
 */
class Response
{
    private int $statusCode = 200;
    private array $headers = [];
    private mixed $content = '';

    public function __construct(mixed $content = '', int $statusCode = 200, array $headers = [])
    {
        $this->content = $content;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
    }

    /**
     * Create JSON response
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        return new self(json_encode($data), $statusCode, $headers);
    }

    /**
     * Create success response
     */
    public static function success(string $message = 'Success', mixed $data = null, int $statusCode = 200): self
    {
        $response = [
            'success' => true,
            'message' => $message,
            'timestamp' => date('c')
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return self::json($response, $statusCode);
    }

    /**
     * Create error response
     */
    public static function error(string $message, int $statusCode = 400, mixed $errors = null): self
    {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => date('c')
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return self::json($response, $statusCode);
    }

    /**
     * Create validation error response
     */
    public static function validationError(array $errors, string $message = 'Validation failed'): self
    {
        return self::error($message, 422, $errors);
    }

    /**
     * Create redirect response
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new self('', $statusCode, ['Location' => $url]);
    }

    /**
     * Create redirect back response
     */
    public static function back(string $fallback = '/'): self
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? $fallback;
        return self::redirect($referer);
    }

    /**
     * Set status code
     */
    public function status(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    /**
     * Add header
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Add multiple headers
     */
    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    /**
     * Set CORS headers
     */
    public function cors(array $origins = ['*'], array $methods = ['GET', 'POST'], array $headers = ['*']): self
    {
        $this->headers['Access-Control-Allow-Origin'] = implode(', ', $origins);
        $this->headers['Access-Control-Allow-Methods'] = implode(', ', $methods);
        $this->headers['Access-Control-Allow-Headers'] = implode(', ', $headers);
        return $this;
    }

    /**
     * Send the response
     */
    public function send(): void
    {
        // Set status code
        http_response_code($this->statusCode);

        // Set headers
        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        // Output content
        echo $this->content;
    }

    /**
     * Get status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Get headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get content
     */
    public function getContent(): mixed
    {
        return $this->content;
    }

    /**
     * Convert to string
     */
    public function __toString(): string
    {
        return (string) $this->content;
    }
}
