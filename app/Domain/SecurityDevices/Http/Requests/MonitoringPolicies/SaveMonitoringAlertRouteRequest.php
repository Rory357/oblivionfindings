<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use Illuminate\Validation\Rule;

final class SaveMonitoringAlertRouteRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('alertRoute');

        return [
            'version' => $this->isUpdating('alertRoute') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'scope_kind' => [...$required, Rule::in(['application', 'site', 'device', 'monitor'])],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'monitor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_domain' => ['sometimes', 'nullable', 'string', 'max:64'],
            'device_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'monitor_kind' => ['sometimes', 'nullable', 'string', 'max:64'],
            'warning_notify_in_app' => ['sometimes', 'boolean'],
            'warning_notify_email' => ['sometimes', 'boolean'],
            'warning_open_it_ticket' => ['sometimes', 'boolean'],
            'critical_notify_in_app' => ['sometimes', 'boolean'],
            'critical_notify_email' => ['sometimes', 'boolean'],
            'critical_open_it_ticket' => ['sometimes', 'boolean'],
            'critical_escalate_control_room' => ['sometimes', 'boolean'],
            'recipient_role' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
