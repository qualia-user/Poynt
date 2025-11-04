<?php

namespace App\Services\Support;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Small helper focused on normalising common Poynt payload shapes so they can
 * be stored in the database schema defined by the canonical SQL definitions.
 *
 * The goal is to centralise the defensive JSON encoding and timestamp parsing
 * logic so individual services stay tidy and, most importantly, always honour
 * the NOT NULL + DEFAULT constraints introduced in the new schema.
 */
class PoyntDataFormatter
{
    public static function jsonObject(mixed $value): string
    {
        if ($value === null) {
            return '{}';
        }

        $encoded = json_encode($value);

        return $encoded === false ? '{}' : $encoded;
    }

    public static function jsonArray(mixed $value): string
    {
        if ($value === null) {
            return '[]';
        }

        $encoded = json_encode($value);

        return $encoded === false ? '[]' : $encoded;
    }

    public static function optionalTimestamp(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:sP');
        }

        try {
            return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return null;
        }
    }

    public static function optionalBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim(strtolower($value));

            if ($value === '') {
                return null;
            }

            if ($value === 'true' || $value === 't' || $value === 'yes') {
                return true;
            }

            if ($value === 'false' || $value === 'f' || $value === 'no') {
                return false;
            }

            if ($value === '1') {
                return true;
            }

            if ($value === '0') {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return (bool) $value;
    }

    public static function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function optionalNumericString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (string) $value : null;
    }

    public static function amount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = $value['amount'] ?? null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    /**
     * Render a PHP array of scalar values as a PostgreSQL text array literal.
     */
    public static function postgresTextArray(array $values): string
    {
        if (empty($values)) {
            return '{}';
        }

        $escaped = array_map(
            static function ($value): string {
                $value = (string) $value;
                $value = str_replace(['\\', '"'], ['\\\\', '\"'], $value);

                return '"' . $value . '"';
            },
            $values
        );

        return '{' . implode(',', $escaped) . '}';
    }
}
