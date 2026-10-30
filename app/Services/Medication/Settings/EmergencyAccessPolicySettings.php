<?php

namespace App\Services\Medication\Settings;

use App\Models\BreakGlassPolicy;

/** Adapter for P11 drafts; the existing runtime policy remains canonical. */
final class EmergencyAccessPolicySettings
{
    public const PERMISSION = 'medications.emergency_policy.manage';

    public function values(bool $lock = false): array
    {
        $query = BreakGlassPolicy::query()->oldest('id');
        $policy = ($lock ? $query->lockForUpdate() : $query)->first() ?? new BreakGlassPolicy(BreakGlassPolicy::defaults());

        return collect(BreakGlassPolicy::defaults())->mapWithKeys(fn ($default, string $key): array => [
            $key => $key === 'reason_required' ? ($policy->$key ? 'yes' : 'no') : (string) $policy->$key,
        ])->all();
    }

    /** Caller holds MedicationSettingsStore's shared revision lock. */
    public function write(string $key, string $value): void
    {
        BreakGlassPolicy::updateApplicationPolicy([$key => $key === 'reason_required' ? $value === 'yes' : ($key === 'second_person' ? $value : (int) $value)]);
    }
}
