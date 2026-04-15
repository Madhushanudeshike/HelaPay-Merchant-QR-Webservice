<?php

declare(strict_types=1);

/**
 * Simple input validator.
 */
class Validator
{
    private array $errors = [];

    public function required(array $data, array $fields): static
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $this->errors[] = "Field '{$field}' is required.";
            }
        }
        return $this;
    }

    public function numeric(array $data, string $field, float $min = 0): static
    {
        if (isset($data[$field]) && (!is_numeric($data[$field]) || (float)$data[$field] < $min)) {
            $this->errors[] = "Field '{$field}' must be a number >= {$min}.";
        }
        return $this;
    }

    public function date(array $data, string $field, string $format = 'Y-m-d'): static
    {
        if (isset($data[$field])) {
            $d = DateTime::createFromFormat($format, $data[$field]);
            if (!$d || $d->format($format) !== $data[$field]) {
                $this->errors[] = "Field '{$field}' must be a valid date ({$format}).";
            }
        }
        return $this;
    }

    public function maxLength(array $data, string $field, int $max): static
    {
        if (isset($data[$field]) && strlen((string)$data[$field]) > $max) {
            $this->errors[] = "Field '{$field}' must be at most {$max} characters.";
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** Parse JSON body or abort with 400. */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            Response::error('Invalid JSON body', 400);
        }
        return $data;
    }
}
