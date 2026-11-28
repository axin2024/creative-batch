<?php

declare(strict_types=1);

namespace CreativeBatch;

final class CampaignData
{
    public static function locale(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 24 || !preg_match('/\A[a-z]{2,3}(?:-[a-z0-9]{2,8})*\z/iD', $value)) {
            throw new Problem(422, 'invalid-locale', 'Use a language tag such as ar or fr-ca.', ['field' => 'locale']);
        }
        return strtolower($value);
    }

    public static function territory(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Z]{2}\z/D', $value)) {
            throw new Problem(422, 'invalid-territory', 'Use an uppercase two-letter territory code.', ['field' => 'territory']);
        }
        return $value;
    }

    public static function rectangle(mixed $value): array
    {
        $parts = Values::listOf($value, 4, 4);
        [$x, $y, $w, $h] = array_map(static fn(mixed $n): int => Values::integer($n, 0, 10000), $parts);
        if ($w < 50 || $h < 50 || $x + $w > 10000 || $y + $h > 10000) {
            throw Problem::invalid('Layer coordinates and dimensions must fit the design grid of 10000 units.');
        }
        return [$x, $y, $w, $h];
    }

    public static function decodeRow(array $row, string ...$fields): array
    {
        unset($row['tenant']);
        foreach ($fields as $field) {
            $row[$field] = Values::decode($row[$field]);
        }
        return $row;
    }

    public static function releaseField(string $field, string $message, array $detail = []): Problem
    {
        return new Problem(422, 'release-blocked', $message, ['field' => $field] + $detail);
    }

    public static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(self::canonical(...), $value);
    }

    public static function digest(array $value): string
    {
        return hash('sha256', Values::json(self::canonical($value)));
    }
}
