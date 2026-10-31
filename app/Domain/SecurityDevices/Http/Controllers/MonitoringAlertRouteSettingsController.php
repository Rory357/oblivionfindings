<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringAlertRoute;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringAlertRouteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringAlertRouteSettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringAlertRouteRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $this->assertScopedRuleAttributes($actor, $values);
        $route = $this->governed(fn () => $this->authoring->createAlertRoute($actor, $values));

        return response()->json(['alert_route' => $this->state($route)], 201);
    }

    public function update(
        SaveMonitoringAlertRouteRequest $request,
        MonitoringAlertRoute $alertRoute,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $alertRoute);
        $values = $request->validated();
        $this->assertScopedRuleAttributes($actor, [
            'scope_kind' => $values['scope_kind'] ?? $alertRoute->scope_kind,
            'site_id' => array_key_exists('site_id', $values) ? $values['site_id'] : $alertRoute->site_id,
            'device_id' => array_key_exists('device_id', $values) ? $values['device_id'] : $alertRoute->device_id,
            'monitor_id' => array_key_exists('monitor_id', $values) ? $values['monitor_id'] : $alertRoute->monitor_id,
        ]);
        $updated = $this->governed(fn () => $this->authoring->updateAlertRoute(
            $actor,
            $alertRoute,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['alert_route' => $this->state($updated)]);
    }

    public function deactivate(
        MonitoringPolicyLifecycleRequest $request,
        MonitoringAlertRoute $alertRoute,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $alertRoute);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->deactivateAlertRoute(
            $actor,
            $alertRoute,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['alert_route' => $this->state($updated)]);
    }

    public function reactivate(
        MonitoringPolicyLifecycleRequest $request,
        MonitoringAlertRoute $alertRoute,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $alertRoute);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->reactivateAlertRoute(
            $actor,
            $alertRoute,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['alert_route' => $this->state($updated)]);
    }
}
