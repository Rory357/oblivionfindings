<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringDependencyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringDependencySettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringDependencyRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $this->assertDependencyAttributes($actor, $values);
        $record = $this->governed(fn () => $this->authoring->createManualDependency($actor, $values));

        return response()->json(['dependency' => $this->state($record)], 201);
    }

    public function update(SaveMonitoringDependencyRequest $request, MonitorDependency $dependency): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertDependencyRecord($actor, $dependency);
        $values = $request->validated();
        $proposed = [
            'site_id' => $values['site_id'] ?? $dependency->site_id,
            'upstream_monitor_id' => $values['upstream_monitor_id'] ?? $dependency->upstream_monitor_id,
            'downstream_monitor_id' => $values['downstream_monitor_id'] ?? $dependency->downstream_monitor_id,
        ];
        $this->assertDependencyAttributes($actor, $proposed);
        $record = $this->governed(fn () => $this->authoring->updateManualDependency(
            $actor,
            $dependency,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['dependency' => $this->state($record)]);
    }

    public function deactivate(MonitoringPolicyLifecycleRequest $request, MonitorDependency $dependency): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertDependencyRecord($actor, $dependency);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->deactivateManualDependency(
            $actor,
            $dependency,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['dependency' => $this->state($record)]);
    }

    /** @param array<string, mixed> $values */
    private function assertDependencyAttributes(User $actor, array $values): void
    {
        $this->access->assertCanViewSite($actor, (int) $values['site_id']);
        $this->accessibleMonitor($actor, (int) $values['upstream_monitor_id']);
        $this->accessibleMonitor($actor, (int) $values['downstream_monitor_id']);
    }

    private function assertDependencyRecord(User $actor, MonitorDependency $dependency): void
    {
        $this->assertDependencyAttributes($actor, [
            'site_id' => $dependency->site_id,
            'upstream_monitor_id' => $dependency->upstream_monitor_id,
            'downstream_monitor_id' => $dependency->downstream_monitor_id,
        ]);
    }
}
