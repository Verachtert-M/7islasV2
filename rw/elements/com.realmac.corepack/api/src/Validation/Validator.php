<?php

namespace App\Validation;

/**
 * Validation system for CMS data
 */
class Validator
{
    private array $rules = [];
    private array $messages = [];
    private array $errors = [];

    public function __construct(array $rules = [], array $messages = [])
    {
        $this->rules = $this->parseRules($rules);
        $this->messages = $messages;
    }

    /**
     * Parse string rules into array format
     */
    private function parseRules(array $rules): array
    {
        $parsed = [];

        foreach ($rules as $field => $fieldRules) {
            if (is_string($fieldRules)) {
                // Parse string rules like "required|string|max:100"
                $parsed[$field] = $this->parseStringRules($fieldRules);
            } else {
                // Already in array format
                $parsed[$field] = $fieldRules;
            }
        }

        return $parsed;
    }

    /**
     * Parse string rules into array format
     */
    private function parseStringRules(string $rules): array
    {
        $parsed = [];
        $ruleList = explode('|', $rules);

        foreach ($ruleList as $rule) {
            $rule = trim($rule);
            if (empty($rule)) {
                continue;
            }

            if (str_contains($rule, ':')) {
                // Rule with parameter (e.g., "max:100")
                [$ruleName, $parameter] = explode(':', $rule, 2);
                $parsed[] = [trim($ruleName), trim($parameter)];
            } else {
                // Simple rule (e.g., "required")
                $parsed[] = trim($rule);
            }
        }

        return $parsed;
    }

    /**
     * Validate data against rules
     */
    public function validate(array $data): ValidationResult
    {
        $this->errors = [];

        foreach ($this->rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $this->validateField($field, $value, $fieldRules);
        }

        return new ValidationResult(empty($this->errors), $this->errors);
    }

    /**
     * Add validation rule
     */
    public function addRule(string $field, string $rule, mixed $parameter = null): self
    {
        if (!isset($this->rules[$field])) {
            $this->rules[$field] = [];
        }

        if ($parameter !== null) {
            $this->rules[$field][] = [$rule, $parameter];
        } else {
            $this->rules[$field][] = $rule;
        }

        return $this;
    }

    /**
     * Set custom error message
     */
    public function setMessage(string $field, string $rule, string $message): self
    {
        $this->messages["{$field}.{$rule}"] = $message;
        return $this;
    }

    /**
     * Validate single field
     */
    private function validateField(string $field, mixed $value, array $rules): void
    {
        foreach ($rules as $rule) {
            $ruleName = is_array($rule) ? $rule[0] : $rule;
            $parameter = is_array($rule) ? $rule[1] : null;

            if (!$this->applyRule($field, $value, $ruleName, $parameter)) {
                break; // Stop on first failure for this field
            }
        }
    }

    /**
     * Apply validation rule
     */
    private function applyRule(string $field, mixed $value, string $rule, mixed $parameter): bool
    {
        $isValid = match ($rule) {
            'required' => $this->validateRequired($value),
            'string' => $this->validateString($value),
            'integer' => $this->validateInteger($value),
            'numeric' => $this->validateNumeric($value),
            'boolean' => $this->validateBoolean($value),
            'array' => $this->validateArray($value),
            'email' => $this->validateEmail($value),
            'url' => $this->validateUrl($value),
            'date' => $this->validateDate($value),
            'min' => $this->validateMin($value, $parameter),
            'max' => $this->validateMax($value, $parameter),
            'min_length' => $this->validateMinLength($value, $parameter),
            'max_length' => $this->validateMaxLength($value, $parameter),
            'regex' => $this->validateRegex($value, $parameter),
            'in' => $this->validateIn($value, $parameter),
            'not_in' => $this->validateNotIn($value, $parameter),
            'unique' => $this->validateUnique($value, $parameter),
            'slug' => $this->validateSlug($value),
            'status' => $this->validateStatus($value),
            default => throw new \InvalidArgumentException("Unknown validation rule: {$rule}")
        };

        if (!$isValid) {
            $this->addError($field, $rule, $parameter);
        }

        return $isValid;
    }

    /**
     * Add validation error
     */
    private function addError(string $field, string $rule, mixed $parameter): void
    {
        $messageKey = "{$field}.{$rule}";
        $message = $this->messages[$messageKey] ?? $this->getDefaultMessage($field, $rule, $parameter);

        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
    }

    /**
     * Get default error message
     */
    private function getDefaultMessage(string $field, string $rule, mixed $parameter): string
    {
        return match ($rule) {
            'required' => "The {$field} field is required.",
            'string' => "The {$field} field must be a string.",
            'integer' => "The {$field} field must be an integer.",
            'numeric' => "The {$field} field must be numeric.",
            'boolean' => "The {$field} field must be a boolean.",
            'array' => "The {$field} field must be an array.",
            'email' => "The {$field} field must be a valid email address.",
            'url' => "The {$field} field must be a valid URL.",
            'date' => "The {$field} field must be a valid date.",
            'min' => "The {$field} field must be at least {$parameter}.",
            'max' => "The {$field} field must not exceed {$parameter}.",
            'min_length' => "The {$field} field must be at least {$parameter} characters.",
            'max_length' => "The {$field} field must not exceed {$parameter} characters.",
            'regex' => "The {$field} field format is invalid.",
            'in' => "The selected {$field} is invalid.",
            'not_in' => "The selected {$field} is invalid.",
            'unique' => "The {$field} has already been taken.",
            'slug' => "The {$field} field must be a valid slug.",
            'status' => "The {$field} field must be a valid status.",
            default => "The {$field} field is invalid."
        };
    }

    // Validation methods
    private function validateRequired(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function validateString(mixed $value): bool
    {
        return is_string($value);
    }

    private function validateInteger(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private function validateNumeric(mixed $value): bool
    {
        return is_numeric($value);
    }

    private function validateBoolean(mixed $value): bool
    {
        return is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true);
    }

    private function validateArray(mixed $value): bool
    {
        return is_array($value);
    }

    private function validateEmail(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function validateUrl(mixed $value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    private function validateDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function validateMin(mixed $value, mixed $min): bool
    {
        if (is_numeric($value)) {
            return $value >= $min;
        }

        if (is_string($value)) {
            return strlen($value) >= $min;
        }

        if (is_array($value)) {
            return count($value) >= $min;
        }

        return false;
    }

    private function validateMax(mixed $value, mixed $max): bool
    {
        if (is_numeric($value)) {
            return $value <= $max;
        }

        if (is_string($value)) {
            return strlen($value) <= $max;
        }

        if (is_array($value)) {
            return count($value) <= $max;
        }

        return false;
    }

    private function validateMinLength(mixed $value, int $min): bool
    {
        return is_string($value) && strlen($value) >= $min;
    }

    private function validateMaxLength(mixed $value, int $max): bool
    {
        return is_string($value) && strlen($value) <= $max;
    }

    private function validateRegex(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function validateIn(mixed $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }

    private function validateNotIn(mixed $value, array $forbidden): bool
    {
        return !in_array($value, $forbidden, true);
    }

    private function validateUnique(mixed $value, callable $checker): bool
    {
        return !$checker($value);
    }

    private function validateSlug(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1;
    }

    private function validateStatus(mixed $value): bool
    {
        return in_array($value, ['published', 'draft', 'private'], true);
    }
}
