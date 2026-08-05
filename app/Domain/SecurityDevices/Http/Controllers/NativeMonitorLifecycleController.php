<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\NativeMonitoringDefinitionService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NativeMonitorLifecycleController extends Controller
{
    public function __construct(
        private readonly NativeMonitoringDefinitionService $definitions,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $viewer = $this->manager($request);
        $validated = $request->validate($this->rules(false));
        $device = $this->accessibleDevice($viewer, (int) $validated['device_id']);
        $monitor = $this->definitions->createMonitor($viewer, $device, $validated);

        return response()->json(['monitor' => $this->safeMonitor($monitor)], 201);
    }

    public function update(Request $request, int $monitor): JsonResponse
    {
        $viewer = $this->manager($request);
        $record = $this->accessibleMonitor($viewer, $monitor);
        $validated = $request->validate($this->rules(true));
        $updated = $this->definitions->updateMonitor($viewer, $record, $validated);

        return response()->json(['monitor' => $this->safeMonitor($updated)]);
    }

    public function deactivate(Request $request, int $monitor): JsonResponse
    {
        $viewer = $this->manager($request);
        $record = $this->accessibleMonitor($viewer, $monitor);
        $validated = $request->validate([
            'reason_code' => ['required', 'string', Rule::in([
                'replaced',
                'obsolete',
                'coverage_removed',
                'device_retired',
            ])],
        ]);
        $updated = $this->definitions->deactivateMonitor(
            $viewer,
            $record,
            (string) $validated['reason_code'],
        );

        return response()->json([
            'monitor' => ['id' => (int) $updated->id, 'enabled' => (bool) $updated->is_enabled],
        ]);
    }

    private function manager(Request $request): User
    {
        $viewer = $request->user();
        abort_unless($viewer instanceof User && $viewer->canDo('securityDevices.monitoring.manage'), 403);

        return $viewer;
    }

    private function accessibleDevice(User $viewer, int $deviceId): Device
    {
        return $this->access->visibleDevices($viewer)
            ->whereKey($deviceId)
            ->firstOrFail();
    }

    private function accessibleMonitor(User $viewer, int $monitorId): Monitor
    {
        return Monitor::query()
            ->whereKey($monitorId)
            ->whereNull('collector_id')
            ->whereIn('kind', NativeMonitoringDefinitionService::directKindValues())
            ->whereIn('device_id', $this->access->visibleDevices($viewer)->select('devices.id'))
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating): array
    {
        $sometimes = $updating ? ['sometimes'] : ['required'];

        return [
            'device_id' => $updating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'profile_id' => [...$sometimes, 'integer', 'min:1'],
            'kind' => $updating
                ? ['prohibited']
                : ['required', 'string', Rule::in(collect(NativeMonitoringDefinitionService::directKindOptions())->pluck('value')->all())],
            'name' => [...$sometimes, 'string', 'min:3', 'max:128', 'not_regex:/[\x00-\x1F\x7F]/'],
            'target' => $updating
                ? ['sometimes', 'nullable', 'string', 'max:8192', 'not_regex:/[\x00-\x1F\x7F]/']
                : ['required', 'string', 'max:8192', 'not_regex:/[\x00-\x1F\x7F]/'],
            'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'dns_name' => ['sometimes', 'nullable', 'string', 'max:253'],
            'dns_type' => ['sometimes', 'nullable', 'string', Rule::in(['A', 'AAAA', 'CNAME', 'MX', 'TXT'])],
            'expected_answers' => ['sometimes', 'array', 'max:64'],
            'expected_answers.*' => ['string', 'max:1024', 'not_regex:/[\x00-\x1F\x7F]/'],
            'expected_status' => ['sometimes', 'array', 'min:1', 'max:20'],
            'expected_status.*' => ['integer', 'between:100,599'],
            'warn_days' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
            'credential_reference' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[a-z][a-z0-9._-]{1,31}:[A-Za-z0-9._\/:@-]{1,158}$/'],
            'inventory_profile' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_.-]{1,63}$/'],
            'host_key_fingerprint' => ['sometimes', 'nullable', 'string', 'max:51', 'regex:/^SHA256:[A-Za-z0-9+\/]{43}$/'],
            'affects_availability' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function safeMonitor(Monitor $monitor): array
    {
        return [
            'id' => (int) $monitor->id,
            'name' => $monitor->name,
            'kind' => $monitor->kind?->value ?? (string) $monitor->kind,
            'device_id' => (int) $monitor->device_id,
            'profile_id' => (int) $monitor->profile_id,
            'collection_mode' => 'central_direct',
            'enabled' => (bool) $monitor->is_enabled,
        ];
    }
}
