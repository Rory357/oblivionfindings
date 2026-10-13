<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Services\MaintenanceCommandService;
use App\Domain\Monitoring\Services\MaintenancePolicyAccess;
use App\Domain\Monitoring\Services\MaintenanceSchedule;
use App\Domain\Monitoring\Services\MonitoringPolicyRules;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\CancelMonitoringMaintenanceRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\RecoverMonitoringMaintenanceRequest;
use App\Domain\SecurityDevices\Http\Requests\MonitoringPolicies\SaveMonitoringMaintenanceRequest;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

final class MonitoringMaintenanceSettingsController extends MonitoringPolicySettingsController
{
    public function preview(SaveMonitoringMaintenanceRequest $request): JsonResponse
    {
        $this->queryFree($request);
        abort_unless(Schema::hasColumns('monitoring_maintenance_windows', ['dst_overlap', 'dst_gap']), 503, 'Maintenance timing settings need an application update.');
        $values = $request->validated();
        $access = app(MaintenancePolicyAccess::class);
        $data = $access->within($request->user(), (int) $values['expected_actor_id'], null, (int) $values['site_id'], false,
            function (User $actor, CurrentAuthorizationReads $reads) use ($values, $access): array {
                $access->scope($actor, $values, $reads);
                $schedule = app(MonitoringPolicyRules::class)->maintenance($values);
                $rows = app(MaintenanceSchedule::class)->occurrences($schedule);

                return [
                    'schema_version' => 1, 'actor_id' => (int) $actor->id, 'owner' => 'Monitoring',
                    'scope' => Arr::only($schedule, ['site_id', 'device_id', 'monitor_id']),
                    'timezone' => $schedule['timezone'], 'dst_overlap' => $schedule['dst_overlap'], 'dst_gap' => $schedule['dst_gap'],
                    'precision' => 'seconds', 'commits_change' => false,
                    'scheduled_occurrences' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'ready')),
                    'skipped_occurrences' => count(array_filter($rows, fn (array $row): bool => $row['state'] === 'skipped')),
                    'truncated' => count($rows) > 10,
                    'occurrences' => array_map(fn (array $row): array => [...$row,
                        'starts_at' => $row['starts_at']?->toIso8601String(), 'ends_at' => $row['ends_at']?->toIso8601String(),
                    ], array_slice($rows, 0, 10)),
                ];
            });

        return response()->json(['preview' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveMonitoringMaintenanceRequest $request): JsonResponse
    {
        return $this->command($request, 'create', null, 201);
    }

    public function update(SaveMonitoringMaintenanceRequest $request, int $window): JsonResponse
    {
        return $this->command($request, 'update', $window);
    }

    public function cancel(CancelMonitoringMaintenanceRequest $request, int $window): JsonResponse
    {
        return $this->command($request, 'cancel', $window);
    }

    public function recover(RecoverMonitoringMaintenanceRequest $request, string $requestUuid): JsonResponse
    {
        $values = $request->validated();
        $reply = app(MaintenanceCommandService::class)->recover($request->user(), (int) $values['expected_actor_id'], $requestUuid, (int) $values['site_id']);

        return response()->json($reply)->header('Cache-Control', 'private, no-store');
    }

    private function command(Request $request, string $operation, ?int $target, int $status = 200): JsonResponse
    {
        $this->queryFree($request);
        $reply = $this->governed(fn () => app(MaintenanceCommandService::class)->execute($request->user(), $operation, $target, $request->validated()));

        return response()->json($reply, $status)->header('Cache-Control', 'private, no-store');
    }

    private function queryFree(Request $request): void
    {
        abort_if($request->query() !== [], 400, 'invalid_request');
    }
}
