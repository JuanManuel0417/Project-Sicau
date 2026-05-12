<?php

namespace App\Core;

class Validator
{
    private array $errors = [];

    public function required(string $field, $value, string $message): self
    {
        if ($value === null || trim((string)$value) === '') {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function email(string $field, $value, string $message): self
    {
        if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function maxLength(string $field, $value, int $length, string $message): self
    {
        if (mb_strlen((string)$value) > $length) {
            $this->errors[$field] = $message;
        }
        return $this;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }
}
