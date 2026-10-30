<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use Illuminate\Validation\Rule;

final class SaveMonitoringThresholdRuleRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('thresholdRule');

        return [
            'version' => $this->isUpdating('thresholdRule') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'name' => [...$required, 'string', 'min:3', 'max:128'],
            'scope_kind' => [...$required, Rule::in(['application', 'site', 'device', 'monitor'])],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'monitor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_domain' => ['sometimes', 'nullable', 'string', 'max:64'],
            'device_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'monitor_kind' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metric_key' => ['sometimes', 'nullable', Rule::in(MonitoringPolicyRules::ALLOWED_METRIC_KEYS)],
            'threshold_direction' => ['sometimes', Rule::in(['above', 'below'])],
            'warning_rising_threshold' => ['sometimes', 'nullable', 'numeric'],
            'warning_falling_threshold' => ['sometimes', 'nullable', 'numeric'],
            'critical_rising_threshold' => ['sometimes', 'nullable', 'numeric'],
            'critical_falling_threshold' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}
