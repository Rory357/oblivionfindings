<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringCoverageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringCoverageSettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringCoverageRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $values = $request->validated();
        $this->assertCoverageAttributes($actor, $values);
        $record = $this->governed(fn () => $this->authoring->createCoverageExpectation($actor, $values));

        return response()->json(['expectation' => $this->state($record)], 201);
    }

    public function update(SaveMonitoringCoverageRequest $request, MonitoringCoverageExpectation $expectation): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCoverageScope($actor, $expectation);
        $values = $request->validated();
        if (array_key_exists('site_id', $values)) {
            $this->assertCoverageAttributes($actor, $values);
        }
        $record = $this->governed(fn () => $this->authoring->updateCoverageExpectation(
            $actor,
            $expectation,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['expectation' => $this->state($record)]);
    }

    public function deactivate(MonitoringPolicyLifecycleRequest $request, MonitoringCoverageExpectation $expectation): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCoverageScope($actor, $expectation);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->deactivateCoverageExpectation(
            $actor,
            $expectation,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['expectation' => $this->state($record)]);
    }

    public function reactivate(MonitoringPolicyLifecycleRequest $request, MonitoringCoverageExpectation $expectation): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertCoverageScope($actor, $expectation);
        $values = $request->validated();
        $record = $this->governed(fn () => $this->authoring->reactivateCoverageExpectation(
            $actor,
            $expectation,
            (int) $values['version'],
            (string) $values['reason'],
        ));

        return response()->json(['expectation' => $this->state($record)]);
    }
}
