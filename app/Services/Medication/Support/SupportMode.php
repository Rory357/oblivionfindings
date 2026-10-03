<?php

namespace App\Services\Medication\Support;

use Illuminate\Validation\ValidationException;

/** P03's approved words; existing stored scopes remain compatible. */
final class SupportMode
{
    public const MODES = ['self_managed', 'prompted', 'assisted', 'staff_given'];

    public const LABELS = ['self_managed' => 'Self-managed', 'prompted' => 'Prompt', 'assisted' => 'Assist', 'staff_given' => 'Administer'];

    public static function cap(?string $outcome): string
    {
        return match ($outcome) {
            'independent' => 'self_managed', 'prompted' => 'prompted', 'supervised' => 'assisted', default => 'staff_given',
        };
    }

    public static function rank(string $mode): int
    {
        return array_search($mode, self::MODES, true) === false ? 3 : (int) array_search($mode, self::MODES, true);
    }

    public static function needsAgreement(string $mode): bool
    {
        return in_array($mode, ['self_managed', 'prompted'], true);
    }

    public static function loosens(string $mode, string $previous): bool
    {
        return self::rank($mode) < self::rank($previous);
    }

    public static function validate(string $mode, string $cap, bool $controlled): void
    {
        if (! in_array($mode, self::MODES, true) || self::rank($mode) < max(self::rank($cap), $controlled ? 2 : 0)) {
            throw ValidationException::withMessages(['med_scope' => $controlled ? 'Controlled medicines are Assist or Administer.' : 'Support cannot be more independent than the assessment allows.']);
        }
    }
}
