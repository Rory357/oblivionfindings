<?php

namespace App\Domain\Clinical\Enums;

enum ObservationType: string
{
    case Vitals = 'vitals';
    case Weight = 'weight';
    case Bowel = 'bowel';
    case Sleep = 'sleep';
    case FluidIntake = 'fluid_intake';
    case Pain = 'pain';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Vitals => 'Vital Signs',
            self::Weight => 'Weight',
            self::Bowel => 'Bowel Chart',
            self::Sleep => 'Sleep Log',
            self::FluidIntake => 'Fluid Intake',
            self::Pain => 'Pain Assessment',
            self::General => 'General Observation',
        };
    }

    /**
     * Whether this observation type requires clinical-level permissions.
     */
    public function requiresClinicalPermission(): bool
    {
        return match ($this) {
            self::Vitals, self::Pain => true,
            default => false,
        };
    }
}
