<?php
declare(strict_types=1);

/**
 * Input Validator
 * Centralized validation functions for all API inputs
 */

class Validator
{
    private array $errors = [];

    /**
     * Validate and sanitize a string field
     */
    public function string(string $field, string $value, int $minLen = 1, int $maxLen = 255): self
    {
        $value = trim($value);
        if (mb_strlen($value) < $minLen) {
            $this->errors[$field] = "{$field} must be at least {$minLen} characters";
        } elseif (mb_strlen($value) > $maxLen) {
            $this->errors[$field] = "{$field} must be at most {$maxLen} characters";
        }
        return $this;
    }

    /**
     * Validate an email field
     */
    public function email(string $field, string $value): self
    {
        if (!isValidEmail($value)) {
            $this->errors[$field] = 'Invalid email address';
        }
        return $this;
    }

    /**
     * Validate a password (min 8 chars, 1 uppercase, 1 number)
     */
    public function password(string $field, string $value): self
    {
        if (mb_strlen($value) < 8) {
            $this->errors[$field] = 'Password must be at least 8 characters';
        } elseif (!preg_match('/[A-Z]/', $value)) {
            $this->errors[$field] = 'Password must contain at least one uppercase letter';
        } elseif (!preg_match('/[0-9]/', $value)) {
            $this->errors[$field] = 'Password must contain at least one number';
        }
        return $this;
    }

    /**
     * Validate a cents amount (positive integer within range)
     */
    public function cents(string $field, int $value, int $min = 1, int $max = PHP_INT_MAX): self
    {
        if ($value < $min) {
            $this->errors[$field] = "{$field} must be at least {$min}";
        } elseif ($value > $max) {
            $this->errors[$field] = "{$field} must be at most {$max}";
        }
        return $this;
    }

    /**
     * Validate a date field (Y-m-d format)
     */
    public function date(string $field, string $value): self
    {
        $date = DateTime::createFromFormat('Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            $this->errors[$field] = 'Invalid date format (expected YYYY-MM-DD)';
        }
        return $this;
    }

    /**
     * Validate a HEX color field
     */
    public function hexColor(string $field, string $value): self
    {
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            $this->errors[$field] = 'Invalid HEX color format';
        }
        return $this;
    }

    /**
     * Validate a slug (URL-friendly string)
     */
    public function slug(string $field, string $value): self
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
            $this->errors[$field] = 'Invalid slug (only lowercase letters, numbers, and hyphens)';
        }
        return $this;
    }

    /**
     * Validate an enum value
     */
    public function enum(string $field, string $value, array $allowed): self
    {
        if (!in_array($value, $allowed, true)) {
            $allowedStr = implode(', ', $allowed);
            $this->errors[$field] = "{$field} must be one of: {$allowedStr}";
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function isValid(): bool
    {
        return empty($this->errors);
    }

    /**
     * Get all validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Throw validation error response if invalid
     */
    public function validate(): void
    {
        if (!$this->isValid()) {
            \Response::validationError($this->errors);
        }
    }
}
