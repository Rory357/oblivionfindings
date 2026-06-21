<?php

namespace App\Domain\Clinical\Enums;

/**
 * ACVPU level of consciousness (the NEWS2 replacement for AVPU).
 *
 * "New confusion" (C) is scored the same as V/P/U in NEWS2 — any value other
 * than Alert contributes the maximum 3 points to the aggregate score.
 */
enum Acvpu: string
{
    case Alert = 'A';
    case Confusion = 'C';
    case Voice = 'V';
    case Pain = 'P';
    case Unresponsive = 'U';

    public function label(): string
    {
        return match ($this) {
            self::Alert => 'Alert',
            self::Confusion => 'New confusion',
            self::Voice => 'Responds to voice',
            self::Pain => 'Responds to pain',
            self::Unresponsive => 'Unresponsive',
        };
    }

    /** NEWS2: Alert scores 0; everything else scores 3. */
    public function news2Points(): int
    {
        return $this === self::Alert ? 0 : 3;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
