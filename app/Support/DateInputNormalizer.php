<?php

namespace App\Support;

class DateInputNormalizer
{
    public static function toIsoDate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])
                ? sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3])
                : $value;
        }

        if (preg_match('/^(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(\d{4})$/', $value, $matches) === 1) {
            return checkdate((int) $matches[1], (int) $matches[2], (int) $matches[3])
                ? sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[1], (int) $matches[2])
                : $value;
        }

        return $value;
    }
}
