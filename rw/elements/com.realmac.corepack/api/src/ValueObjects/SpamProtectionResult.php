<?php

namespace App\ValueObjects;

/**
 * Value object representing the result of spam protection verification
 */
class SpamProtectionResult
{
    public function __construct(
        private bool $success,
        private ?string $error = null,
        private ?string $errorCode = null,
        private array $metadata = []
    ) {}

    /**
     * Create a successful result
     */
    public static function success(array $metadata = []): self
    {
        return new self(true, null, null, $metadata);
    }

    /**
     * Create a failed result
     */
    public static function failure(string $error, ?string $errorCode = null, array $metadata = []): self
    {
        return new self(false, $error, $errorCode, $metadata);
    }

    /**
     * Check if verification was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Check if verification failed
     */
    public function isFailure(): bool
    {
        return !$this->success;
    }

    /**
     * Get the error message (if any)
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Get the error code (if any)
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get additional metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get a specific metadata value
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Convert to array for logging/debugging
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'error' => $this->error,
            'error_code' => $this->errorCode,
            'metadata' => $this->metadata
        ];
    }
}
