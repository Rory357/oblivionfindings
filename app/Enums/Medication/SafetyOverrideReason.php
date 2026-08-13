<?php

namespace App\Enums\Medication;

enum SafetyOverrideReason: string
{
    case ClinicalDirection = 'clinical_direction';
    case UrgentClinicalNeed = 'urgent_clinical_need';
    case RecordDiscrepancy = 'record_discrepancy';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ClinicalDirection => 'Clinical direction',
            self::UrgentClinicalNeed => 'Urgent clinical need',
            self::RecordDiscrepancy => 'Known record discrepancy',
            self::Other => 'Other',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
            ],
            self::cases(),
        );
    }
}
