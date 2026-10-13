<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use App\Domain\Monitoring\Services\MaintenanceCommandInput;

final class RecoverMonitoringMaintenanceRequest extends MonitoringPolicyFormRequest
{
    public function rules(): array
    {
        return ['expected_actor_id' => ['required', 'integer', 'min:1', 'max:9007199254740991'],
            'site_id' => ['required', 'integer', 'min:1', 'max:9007199254740991']];
    }

    protected function passedValidation(): void
    {
        abort_if($this->json()->all() !== [] || $this->request->all() !== [], 400, 'invalid_request');
        abort_if(array_diff(array_keys($this->query()), array_keys($this->rules())) !== [], 400, 'invalid_request');
        MaintenanceCommandInput::assertClosed($this->query(), array_keys($this->rules()));
    }
}
