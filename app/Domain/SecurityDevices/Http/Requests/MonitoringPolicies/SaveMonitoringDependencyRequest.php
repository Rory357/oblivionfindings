<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

final class SaveMonitoringDependencyRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('dependency');

        return [
            'version' => $this->isUpdating('dependency') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'site_id' => [...$required, 'integer', 'min:1'],
            'upstream_monitor_id' => [...$required, 'integer', 'min:1', 'different:downstream_monitor_id'],
            'downstream_monitor_id' => [...$required, 'integer', 'min:1'],
            'confidence' => [...$required, 'numeric', 'between:0,1'],
        ];
    }
}
