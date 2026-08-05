<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use Illuminate\Validation\Rule;

final class SaveMonitoringMaintenanceRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('window');

        return [
            'version' => $this->isUpdating('window') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'site_id' => [...$required, 'integer', 'min:1'],
            'monitor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'starts_at' => [...$required, 'date'],
            'ends_at' => [...$required, 'date'],
            'recurrence' => ['sometimes', 'nullable', Rule::in(['daily', 'weekly'])],
            'recurrence_until' => ['sometimes', 'nullable', 'date'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'reason' => [...$required, 'string', 'min:10', 'max:500'],
        ];
    }
}
