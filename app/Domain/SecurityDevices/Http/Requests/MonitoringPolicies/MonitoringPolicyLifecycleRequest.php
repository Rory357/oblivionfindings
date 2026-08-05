<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

final class MonitoringPolicyLifecycleRequest extends MonitoringPolicyFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'replacement_profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'confirmation' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
