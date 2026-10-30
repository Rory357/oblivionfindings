<?php

namespace App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies;

use App\Domain\Monitoring\Services\MaintenanceCommandInput;

final class SaveMonitoringMaintenanceRequest extends MonitoringPolicyFormRequest
{
    public function rules(): array
    {
        return MaintenanceCommandInput::rules($this->isUpdating('window') ? 'update' : 'create',
            $this->routeIs('security-devices.settings.monitoring.maintenance.preview'));
    }

    protected function passedValidation(): void
    {
        abort_if($this->query() !== [], 400, 'invalid_request');
        MaintenanceCommandInput::assertClosed($this->all(), array_keys($this->rules()));
    }
}
