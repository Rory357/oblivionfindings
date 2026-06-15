<?php

namespace App\Enums\Medication;

enum NotGivenReason: string
{
    case Absent = 'absent';
    case Destroyed = 'destroyed';
    case DoctorsInstruction = 'doctors_instruction';
    case Fasting = 'fasting';
    case Transferred = 'transferred';
    case Refused = 'refused';
    case SocialLeave = 'social_leave';
    case Hospitalised = 'hospitalised';
    case MedicationUnavailable = 'medication_unavailable';
    case VomitOrNausea = 'vomit_or_nausea';
    case SelfAdministered = 'self_administered';
    case Withheld = 'withheld';
    case OmittedInError = 'omitted_in_error';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Absent => 'Absent',
            self::Destroyed => 'Destroyed',
            self::DoctorsInstruction => 'Doctor\'s instruction',
            self::Fasting => 'Fasting',
            self::Transferred => 'Transferred',
            self::Refused => 'Refused',
            self::SocialLeave => 'Social leave',
            self::Hospitalised => 'Hospitalised',
            self::MedicationUnavailable => 'Medication unavailable',
            self::VomitOrNausea => 'Vomit or nausea',
            self::SelfAdministered => 'Self-administered',
            self::Withheld => 'Withheld',
            self::OmittedInError => 'Omitted in error',
            self::Other => 'Other',
        };
    }

    public function requiresDetail(): bool
    {
        return $this === self::Other;
    }

    public static function options(): array
    {
        return array_map(
            fn (self $reason) => [
                'value' => $reason->value,
                'label' => $reason->label(),
                'requires_detail' => $reason->requiresDetail(),
            ],
            self::cases(),
        );
    }
}
