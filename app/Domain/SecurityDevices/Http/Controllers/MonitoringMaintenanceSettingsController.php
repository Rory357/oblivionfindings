<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringMaintenanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringMaintenanceSettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringMaintenanceRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $this->assertMaintenanceAttributes($actor, $values);
        $record = $this->governed(fn () => $this->authoring->createMaintenanceWindow($actor, $values));

        return response()->json(['window' => $this->state($record)], 201);
    }

    public function update(SaveMonitoringMaintenanceRequest $request, MonitoringMaintenanceWindow $window): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertMaintenanceScope($actor, $window);
        $values = $request->validated();
        $proposed = [
            ...$window->only(['site_id', 'monitor_id', 'device_id']),
            ...Arr::only($values, ['site_id', 'monitor_id', 'device_id']),
        ];
        $this->assertMaintenanceAttributes($actor, $proposed);
        $record = $this->governed(fn () => $this->authoring->updateMaintenanceWindow(
            $actor,
            $window,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['window' => $this->state($record)]);
    }

    public function cancel(MonitoringPolicyLifecycleRequest $request, MonitoringMaintenanceWindow $window): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertMaintenanceScope($actor, $window);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->cancelMaintenanceWindow(
            $actor,
            $window,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['window' => $this->state($record)]);
    }
}
