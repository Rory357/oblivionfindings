<?php

namespace App\Support\Medication;

use InvalidArgumentException;

final class MedicationStockQuantity
{
    public const SCALE = 2;

    public const VALIDATION_RULE = 'decimal:0,'.self::SCALE;

    public const DECIMAL_12_2_MAX = '9999999999.99';

    public const DECIMAL_12_2_MAX_RULE = 'max:'.self::DECIMAL_12_2_MAX;

    public const DECIMAL_10_2_MAX = '99999999.99';

    public const DECIMAL_10_2_MAX_RULE = 'max:'.self::DECIMAL_10_2_MAX;

    private const MINOR_UNITS_PER_UNIT = 10 ** self::SCALE;

    private const DECIMAL_12_2_MAX_MINOR_UNITS = 999_999_999_999;

    private const DECIMAL_10_2_MAX_MINOR_UNITS = 9_999_999_999;

    public static function normalize(int|float|string $value): string
    {
        return self::fromMinorUnits(self::toMinorUnits($value));
    }

    public static function normalizeMovement(int|float|string $value): string
    {
        return self::fromMinorUnits(
            self::toMinorUnits($value, self::DECIMAL_10_2_MAX_MINOR_UNITS),
            self::DECIMAL_10_2_MAX_MINOR_UNITS,
        );
    }

    public static function add(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinorUnits(self::toMinorUnits($left) + self::toMinorUnits($right));
    }

    public static function subtract(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinorUnits(self::toMinorUnits($left) - self::toMinorUnits($right));
    }

    public static function absoluteDifference(int|float|string $left, int|float|string $right): string
    {
        return self::fromMinorUnits(abs(self::toMinorUnits($left) - self::toMinorUnits($right)));
    }

    public static function equals(int|float|string $left, int|float|string $right): bool
    {
        return self::toMinorUnits($left) === self::toMinorUnits($right);
    }

    public static function greaterThan(int|float|string $left, int|float|string $right): bool
    {
        return self::toMinorUnits($left) > self::toMinorUnits($right);
    }

    public static function lessThanOrEqual(int|float|string $left, int|float|string $right): bool
    {
        return self::toMinorUnits($left) <= self::toMinorUnits($right);
    }

    public static function maxZero(int|float|string $value): string
    {
        return self::fromMinorUnits(max(0, self::toMinorUnits($value)));
    }

    public static function toFloat(int|float|string $value): float
    {
        return self::toMinorUnits($value) / self::MINOR_UNITS_PER_UNIT;
    }

    public static function display(int|float|string $value): string
    {
        return rtrim(rtrim(self::normalize($value), '0'), '.');
    }

    private static function toMinorUnits(
        int|float|string $value,
        int $maximumMinorUnits = self::DECIMAL_12_2_MAX_MINOR_UNITS,
    ): int {
        $raw = trim((string) $value);
        if (! preg_match(
            '/\A([+-]?)(?:(\d+)(?:\.(\d{0,'.self::SCALE.'}))?|\.(\d{1,'.self::SCALE.'}))\z/',
            $raw,
            $matches,
        )) {
            throw new InvalidArgumentException('Medication stock quantities must use no more than two decimal places.');
        }

        $whole = ltrim($matches[2] ?? '', '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? ''), self::SCALE, '0');
        $wholeUnits = (int) $whole;
        $fractionUnits = (int) $fraction;

        if (
            (string) $wholeUnits !== $whole
            || $wholeUnits > intdiv(PHP_INT_MAX - $fractionUnits, self::MINOR_UNITS_PER_UNIT)
        ) {
            throw new InvalidArgumentException('Medication stock quantity is outside the supported range.');
        }

        $minorUnits = ($wholeUnits * self::MINOR_UNITS_PER_UNIT) + $fractionUnits;
        $minorUnits = ($matches[1] ?? '') === '-' ? -$minorUnits : $minorUnits;

        if ($minorUnits < -$maximumMinorUnits || $minorUnits > $maximumMinorUnits) {
            throw new InvalidArgumentException('Medication stock quantity is outside the supported storage range.');
        }

        return $minorUnits;
    }

    private static function fromMinorUnits(
        int $minorUnits,
        int $maximumMinorUnits = self::DECIMAL_12_2_MAX_MINOR_UNITS,
    ): string {
        if ($minorUnits < -$maximumMinorUnits || $minorUnits > $maximumMinorUnits) {
            throw new InvalidArgumentException('Medication stock quantity is outside the supported storage range.');
        }

        $negative = $minorUnits < 0;
        $absolute = abs($minorUnits);
        $whole = intdiv($absolute, self::MINOR_UNITS_PER_UNIT);
        $fraction = $absolute % self::MINOR_UNITS_PER_UNIT;

        return ($negative ? '-' : '').$whole.'.'.str_pad((string) $fraction, self::SCALE, '0', STR_PAD_LEFT);
    }
}
