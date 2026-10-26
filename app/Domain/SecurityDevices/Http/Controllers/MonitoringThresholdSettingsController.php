<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringThresholdRule;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringThresholdRuleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringThresholdSettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringThresholdRuleRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $this->assertScopedRuleAttributes($actor, $values);
        $rule = $this->governed(fn () => $this->authoring->createThresholdRule($actor, $values));

        return response()->json(['threshold' => $this->state($rule)], 201);
    }

    public function update(
        SaveMonitoringThresholdRuleRequest $request,
        MonitoringThresholdRule $thresholdRule,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $thresholdRule);
        $values = $request->validated();
        $this->assertScopedRuleAttributes($actor, [
            'scope_kind' => $values['scope_kind'] ?? $thresholdRule->scope_kind,
            'site_id' => array_key_exists('site_id', $values) ? $values['site_id'] : $thresholdRule->site_id,
            'device_id' => array_key_exists('device_id', $values) ? $values['device_id'] : $thresholdRule->device_id,
            'monitor_id' => array_key_exists('monitor_id', $values) ? $values['monitor_id'] : $thresholdRule->monitor_id,
        ]);
        $updated = $this->governed(fn () => $this->authoring->updateThresholdRule(
            $actor,
            $thresholdRule,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['threshold' => $this->state($updated)]);
    }

    public function deactivate(
        MonitoringPolicyLifecycleRequest $request,
        MonitoringThresholdRule $thresholdRule,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $thresholdRule);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->deactivateThresholdRule(
            $actor,
            $thresholdRule,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['threshold' => $this->state($updated)]);
    }

    public function reactivate(
        MonitoringPolicyLifecycleRequest $request,
        MonitoringThresholdRule $thresholdRule,
    ): JsonResponse {
        $actor = $this->actor($request);
        $this->assertScopedRuleScope($actor, $thresholdRule);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->reactivateThresholdRule(
            $actor,
            $thresholdRule,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['threshold' => $this->state($updated)]);
    }
}
