<?php

namespace App\Validation;

/**
 * Validation result container
 */
class ValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly array $errors = []
    ) {}

    /**
     * Check if validation passed
     */
    public function passes(): bool
    {
        return $this->isValid;
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !$this->isValid;
    }

    /**
     * Get all errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for specific field
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if field has errors
     */
    public function hasFieldErrors(string $field): bool
    {
        return !empty($this->errors[$field]);
    }

    /**
     * Get first error message
     */
    public function getFirstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }
        return null;
    }

    /**
     * Get first error for field
     */
    public function getFirstFieldError(string $field): ?string
    {
        return $this->errors[$field][0] ?? null;
    }

    /**
     * Get all error messages as flat array
     */
    public function getAllMessages(): array
    {
        $messages = [];
        foreach ($this->errors as $fieldErrors) {
            $messages = array_merge($messages, $fieldErrors);
        }
        return $messages;
    }

    /**
     * Get error count
     */
    public function getErrorCount(): int
    {
        return array_sum(array_map('count', $this->errors));
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->isValid,
            'errors' => $this->errors,
            'error_count' => $this->getErrorCount()
        ];
    }
}
