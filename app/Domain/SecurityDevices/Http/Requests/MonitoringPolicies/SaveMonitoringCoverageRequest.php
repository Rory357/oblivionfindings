<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use Illuminate\Validation\Rule;

final class SaveMonitoringCoverageRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->requiredWhenCreating('expectation');

        return [
            'version' => $this->isUpdating('expectation') ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'site_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'device_domain' => [...$required, Rule::in(array_column(DeviceDomain::cases(), 'value'))],
            'device_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'capability' => [...$required, 'string', 'max:64'],
            'minimum_count' => ['sometimes', 'integer', 'between:1,100'],
            'support_status' => ['sometimes', Rule::in(['supported', 'unsupported'])],
            'rationale' => [...$required, 'string', 'min:10', 'max:500'],
        ];
    }
}
