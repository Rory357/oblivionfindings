<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\MonitoringPolicyLifecycleRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class MonitoringProfileSettingsController extends MonitoringPolicySettingsController
{
    public function store(SaveMonitoringProfileRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertApplicationAccess($actor);
        $profile = $this->governed(fn () => $this->authoring->createProfile($actor, $request->validated()));

        return response()->json(['profile' => $this->state($profile)], 201);
    }

    public function update(SaveMonitoringProfileRequest $request, MonitoringProfile $profile): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertApplicationAccess($actor);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->updateProfile(
            $actor,
            $profile,
            (int) $values['version'],
            Arr::except($values, ['version']),
        ));

        return response()->json(['profile' => $this->state($updated)]);
    }

    public function deactivate(MonitoringPolicyLifecycleRequest $request, MonitoringProfile $profile): JsonResponse
    {
        $actor = $this->actor($request);
        $this->assertApplicationAccess($actor);
        $values = $request->validated();
        $updated = $this->governed(fn () => $this->authoring->deactivateProfile(
            $actor,
            $profile,
            (int) $values['version'],
            (string) $values['reason'],
            isset($values['replacement_profile_id']) ? (int) $values['replacement_profile_id'] : null,
        ));

        return response()->json(['profile' => $this->state($updated)]);
    }
}
