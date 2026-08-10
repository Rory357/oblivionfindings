<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use Illuminate\Validation\Rule;

final class SaveMonitoringProfileRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('profile');

        return [
            'version' => $this->isUpdating('profile') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'interval_seconds' => ['sometimes', 'integer', 'between:30,86400'],
            'failure_confirmations' => ['sometimes', 'integer', 'between:1,20'],
            'failure_duration_seconds' => ['sometimes', 'integer', 'between:0,86400'],
            'recovery_confirmations' => ['sometimes', 'integer', 'between:1,20'],
            'recovery_duration_seconds' => ['sometimes', 'integer', 'between:0,86400'],
            'stale_after_seconds' => ['sometimes', 'integer', 'between:30,604800'],
            'rising_threshold' => ['sometimes', 'nullable', 'numeric'],
            'falling_threshold' => ['sometimes', 'nullable', 'numeric'],
            'baseline_window_seconds' => ['sometimes', 'integer', 'between:60,604800'],
            'baseline_minimum_samples' => ['sometimes', 'integer', 'between:2,10000'],
            'baseline_deviation_multiplier' => ['sometimes', 'nullable', 'numeric', 'between:0.001,100'],
            'maintenance_policy' => ['sometimes', Rule::in(['suppress_notifications_and_ticketing'])],
            'rollup_policy' => ['sometimes', Rule::in(['worst_applicable'])],
            'retention_policy_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
