<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringRetentionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringRetentionSettingsController extends MonitoringPolicySettingsController
{
    public function preview(SaveMonitoringRetentionRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $current = isset($values['policy_id'])
            ? MonitoringRetentionPolicy::query()->findOrFail((int) $values['policy_id'])
            : null;
        if ($current !== null) {
            $this->assertRetentionScope($actor, $current);
        }
        $attributes = Arr::except($values, ['policy_id', 'confirmation', 'reason']);
        $this->assertRetentionAttributes($actor, $attributes);

        return response()->json([
            'preview' => $this->authoring->previewRetentionPolicy($attributes, $current),
        ]);
    }

    public function store(SaveMonitoringRetentionRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $attributes = Arr::except($values, ['confirmation', 'reason']);
        $this->assertRetentionAttributes($actor, $attributes);
        $record = $this->governed(fn () => $this->authoring->createRetentionPolicy(
            $actor,
            $attributes,
            $values['confirmation'] ?? null,
            $values['reason'] ?? null,
        ));

        return response()->json(['policy' => $this->state($record)], 201);
    }

    public function update(SaveMonitoringRetentionRequest $request, MonitoringRetentionPolicy $policy): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertRetentionScope($actor, $policy);
        $values = $request->validated();
        $attributes = Arr::except($values, ['version', 'confirmation', 'reason']);
        $proposed = [...$policy->only([
            'scope_kind', 'site_id', 'device_id', 'data_class', 'privacy_class',
        ]), ...Arr::only($attributes, [
            'scope_kind', 'site_id', 'device_id', 'data_class', 'privacy_class',
        ])];
        $this->assertRetentionAttributes($actor, $proposed);
        $record = $this->governed(fn () => $this->authoring->updateRetentionPolicy(
            $actor,
            $policy,
            (int) $values['version'],
            $attributes,
            $values['confirmation'] ?? null,
            $values['reason'] ?? null,
        ));

        return response()->json(['policy' => $this->state($record)]);
    }

    public function deactivate(MonitoringPolicyLifecycleRequest $request, MonitoringRetentionPolicy $policy): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertRetentionScope($actor, $policy);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->deactivateRetentionPolicy(
            $actor,
            $policy,
            (int) $values['version'],
            (string) $values['reason'],
            $values['confirmation'] ?? null,
        ));

        return response()->json(['policy' => $this->state($record)]);
    }

    public function reactivate(MonitoringPolicyLifecycleRequest $request, MonitoringRetentionPolicy $policy): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertRetentionScope($actor, $policy);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->reactivateRetentionPolicy(
            $actor,
            $policy,
            (int) $values['version'],
            (string) $values['reason'],
            confirmation: $values['confirmation'] ?? null,
        ));

        return response()->json(['policy' => $this->state($record)]);
    }
}
