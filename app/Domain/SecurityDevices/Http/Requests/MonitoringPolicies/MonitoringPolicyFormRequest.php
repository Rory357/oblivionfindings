<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

abstract class MonitoringPolicyFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->canDo('securityDevices.monitoring.manage');
    }

    protected function isUpdating(string $parameter): bool
    {
        return $this->route($parameter) !== null;
    }

    /** @return list<string> */
    protected function requiredWhenCreating(string $parameter): array
    {
        return $this->isUpdating($parameter) ? ['sometimes'] : ['required'];
    }
}
