<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use Illuminate\Validation\Rule;

final class SaveMonitoringRetentionRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $updating = $this->isUpdating('policy');
        $previewing = $this->routeIs('security-devices.settings.monitoring.retention.preview');
        $required = $updating ? ['sometimes'] : ['required'];

        return [
            'policy_id' => $previewing ? ['sometimes', 'nullable', 'integer', 'min:1'] : ['prohibited'],
            'version' => $updating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'scope_kind' => [...$required, Rule::in(['application', 'site', 'device', 'data_class', 'privacy'])],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'data_class' => ['sometimes', 'nullable', Rule::in([
                'operational', 'tracking_telemetry', 'healthcare_telemetry',
                'security_telemetry', 'configuration',
            ])],
            'privacy_class' => ['sometimes', 'nullable', Rule::in(['standard', 'sensitive', 'restricted'])],
            'raw_days' => [...$required, 'integer', 'between:1,3650'],
            'hourly_days' => [...$required, 'integer', 'between:1,3650'],
            'daily_days' => [...$required, 'integer', 'between:1,3650'],
            'legal_hold' => [...$required, 'boolean'],
            'confirmation' => ['sometimes', 'nullable', 'string', 'max:64'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
