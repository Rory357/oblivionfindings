<?php

namespace App\Services\Eligibility\Rules;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Models\Shift;
use App\Models\User;

class RequiredDriverLicenceRule implements EligibilityRuleInterface
{
    public function evaluate(Shift $shift, User $user): array
    {
        $requiredClass = trim((string) $shift->required_licence_class);
        $requiredEndorsements = collect($shift->required_licence_endorsements ?? [])
            ->map(fn ($endorsement) => strtoupper(trim((string) $endorsement)))
            ->filter()
            ->unique()
            ->values();

        if ($requiredClass === '' && $requiredEndorsements->isEmpty()) {
            return self::pass();
        }

        $tenantId = (int) ($shift->organization_id ?: $user->organization_id ?: 1);
        $eligibility = $user->relationLoaded('hrDriverEligibility')
            ? $user->hrDriverEligibility
            : HrDriverEligibility::query()->where('user_id', $user->id)->first();

        if (! $eligibility || (int) $eligibility->tenant_id !== $tenantId) {
            return self::block('No current driver eligibility record exists for this organisation.');
        }

        if ($eligibility->status !== 'eligible' || ! $eligibility->can_drive_clients) {
            return self::block('This worker is not currently approved for driving shifts.');
        }

        if (! $eligibility->licence_expires_at) {
            return self::block('The driving licence expiry date is not recorded.');
        }

        if (! $shift->starts_at || $eligibility->licence_expires_at->lt($shift->starts_at)) {
            return self::block("The driving licence expires before this shift ({$eligibility->licence_expires_at->format('j M Y')}).");
        }

        $heldClass = preg_replace('/^class\s+/i', '', trim((string) $eligibility->licence_class));

        if ($requiredClass !== '' && strtoupper($heldClass) !== strtoupper($requiredClass)) {
            return self::block("This shift requires a Class {$requiredClass} driving licence.");
        }

        $heldEndorsements = collect($eligibility->licence_endorsements ?? [])
            ->map(fn ($endorsement) => strtoupper(trim((string) $endorsement)))
            ->filter()
            ->unique();
        $missingEndorsements = $requiredEndorsements->diff($heldEndorsements)->values();

        if ($missingEndorsements->isNotEmpty()) {
            $labels = $missingEndorsements->map(fn (string $endorsement) => "{$endorsement} endorsement")->implode(', ');

            return self::block("This shift requires the missing {$labels}.");
        }

        return self::pass();
    }

    private static function block(string $message): array
    {
        return [
            'rule' => 'required_driver_licence',
            'passed' => false,
            'severity' => 'block',
            'overrideable' => false,
            'message' => $message,
        ];
    }

    private static function pass(): array
    {
        return [
            'rule' => 'required_driver_licence',
            'passed' => true,
            'severity' => 'block',
            'overrideable' => false,
            'message' => null,
        ];
    }
}
