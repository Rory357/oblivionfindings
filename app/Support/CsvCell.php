<?php

namespace App\Support;

/** One spreadsheet formula guard for controllers and the report builder. */
final class CsvCell
{
    public static function sanitize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }
        $trimmed = ltrim($value, " \v\f");
        $first = $trimmed[0] ?? '';
        if (in_array($first, ["\t", "\r", "\n"], true)) {
            return "'".$value;
        }
        if (! is_numeric($trimmed) && in_array($first, ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        return $value;
    }
}
