<?php

declare(strict_types=1);

namespace CreativeBatch;

final class Values
{
    public static function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/D', $value) !== 1) {
            throw Problem::invalid('Use an identifier of 1–63 lower-case letters, digits, hyphens or underscores.');
        }
        return $value;
    }

    public static function token(mixed $value, int $length = 32): string
    {
        if (!is_string($value) || strlen($value) !== $length || preg_match('/\A[0-9a-f]+\z/D', $value) !== 1) {
            throw Problem::invalid('Invalid object identity.');
        }
        return $value;
    }

    public static function text(mixed $value, int $limit): string
    {
        if (!is_string($value) || !preg_match('//u', $value) || strlen(trim($value)) < 1 || strlen($value) > $limit) {
            throw Problem::invalid('A valid, nonempty text value is required.');
        }
        return trim($value);
    }

    public static function integer(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            throw Problem::invalid("Use an integer between $min and $max.");
        }
        return $value;
    }

    public static function boolean(mixed $value): bool
    {
        if (!is_bool($value)) {
            throw Problem::invalid('Use a JSON boolean.');
        }
        return $value;
    }

    public static function choose(mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw Problem::invalid('Invalid choice.');
        }
        return $value;
    }

    public static function listOf(mixed $value, int $min, int $max): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw Problem::invalid("Provide a list containing $min–$max items.");
        }
        return $value;
    }

    public static function hex(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A#[0-9a-fA-F]{6}\z/D', $value) !== 1) {
            throw Problem::invalid('Use a six-digit RGB color.');
        }
        return strtolower($value);
    }

    public static function time(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\z/D', $value) !== 1) {
            throw Problem::invalid('A UTC timestamp ending in Z is required.');
        }
        $utc = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($utc === false || $utc->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw Problem::invalid('Invalid UTC timestamp.');
        }
        return $utc->format('Y-m-d H:i:s');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR, 64);
    }

    public static function decode(string $value): mixed
    {
        return json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
