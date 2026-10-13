<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\MonitoringMaintenanceCommandReceipt;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Atomic immutable intent/result around the existing Monitoring authoring owner. */
final class MaintenanceCommandService
{
    public function __construct(
        private readonly MonitoringPolicyAuthoringService $authoring,
        private readonly MaintenancePolicyAccess $access,
        private readonly MonitoringPolicyAuditWriter $audit,
    ) {}

    public function execute(User $claimed, string $operation, ?int $target, array $raw): array
    {
        $this->storage();
        $input = MaintenanceCommandInput::normalize($operation, $raw);
        if (($operation === 'create') !== ($target === null)) {
            throw ValidationException::withMessages(['form' => 'The maintenance operation has an invalid target.']);
        }
        $hash = MaintenanceCommandInput::fingerprint($operation, $target, $input);
        $probe = $this->receipt($input['request_uuid'])->first();
        $mutex = $target ?? $probe?->window_id;

        return $this->access->within($claimed, $input['expected_actor_id'], $mutex,
            (int) ($input['site_id'] ?? 0), true,
            function (User $actor, CurrentAuthorizationReads $reads, ?MonitoringMaintenanceWindow $locked) use ($operation, $target, $input, $hash): array {
                // A duplicate can become visible after waiting for the resource mutex.
                $receipt = $this->receipt($input['request_uuid'])->lockForUpdate()->first();
                if ($receipt) {
                    $this->binding($receipt, $actor, $operation, $target, $hash);

                    return $this->present($receipt, $actor, $reads, $locked);
                }
                $before = $target !== null ? $locked : null;
                abort_if($target !== null && $before === null, 404, 'unavailable');
                $beforeScope = $before ? $this->access->scope($actor, $before->only(['site_id', 'device_id', 'monitor_id']), $reads) : null;
                $attributes = Arr::except($input, ['expected_actor_id', 'request_uuid', 'version']);
                $proposed = $before ? [...$before->only(['site_id', 'device_id', 'monitor_id']), ...Arr::only($attributes, ['site_id', 'device_id', 'monitor_id'])] : $attributes;
                $this->access->scope($actor, $proposed, $reads);
                $reserved = [
                    'request_uuid' => $input['request_uuid'], 'actor_user_id' => $actor->id,
                    'operation' => $operation, 'request_hash' => $hash, 'target_window_id' => $target,
                    'status' => 'reserved', 'before_snapshot' => $before ? json_encode($this->snapshot($before), JSON_THROW_ON_ERROR) : null,
                    'before_scope' => $beforeScope ? json_encode($beforeScope, JSON_THROW_ON_ERROR) : null,
                    'created_at' => CarbonImmutable::now('UTC')->startOfSecond()->format('Y-m-d H:i:s'),
                ];
                // The global UUID uniqueness also serializes collisions across Sites.
                // It is reserved before mutation; audit/receipt failure rolls back all facts.
                $inserted = DB::table('monitoring_maintenance_command_receipts')->insertOrIgnore($reserved);
                $receipt = $this->receipt($input['request_uuid'])->lockForUpdate()->firstOrFail();
                if ($inserted === 0) {
                    $this->binding($receipt, $actor, $operation, $target, $hash);

                    return $this->present($receipt, $actor, $reads, $locked);
                }
                $window = match ($operation) {
                    'create' => $this->authoring->createMaintenanceWindow($actor, $attributes),
                    'update' => $this->authoring->updateMaintenanceWindow($actor, $before, $input['version'], $attributes),
                    'cancel' => $this->authoring->cancelMaintenanceWindow($actor, $before, $input['version'], $input['reason']),
                };
                $receipt->forceFill([
                    'status' => 'committed', 'window_id' => $window->id,
                    'after_snapshot' => $this->snapshot($window),
                    'after_scope' => $this->access->scope($actor, $window->only(['site_id', 'device_id', 'monitor_id']), $reads),
                    'committed_at' => CarbonImmutable::now('UTC')->startOfSecond(),
                ])->save();
                $this->audit->write('monitoring.maintenance.command_committed', $window, $actor, [
                    'request_uuid' => $receipt->request_uuid, 'operation' => $operation,
                    'previous_version' => $receipt->before_snapshot['version'] ?? null,
                    'version' => (int) $window->version, 'site_id' => (int) $window->site_id,
                    'state' => $window->status,
                ]);

                return $this->present($receipt, $actor, $reads, $window);
            });
    }

    /** Read only from the current primary; no recovery lookup can create an intent. */
    public function recover(User $claimed, int $expectedActor, string $uuid, int $siteId): array
    {
        $this->storage();
        $probe = $this->receipt(strtolower($uuid))->first();

        return $this->access->within($claimed, $expectedActor, $probe?->window_id, $siteId, false,
            function (User $actor, CurrentAuthorizationReads $reads, ?MonitoringMaintenanceWindow $window) use ($uuid, $siteId): array {
                $receipt = $reads->query($this->receipt(strtolower($uuid)))->first();
                abort_unless($receipt && $receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
                abort_unless(($receipt->after_scope['site_id'] ?? null) === $siteId, 404, 'unavailable');

                return $this->present($receipt, $actor, $reads, $window);
            });
    }

    private function receipt(string $uuid): Builder
    {
        return MonitoringMaintenanceCommandReceipt::query()->useWritePdo()->where('request_uuid', $uuid);
    }

    private function storage(): void
    {
        abort_unless(Schema::hasColumns('monitoring_maintenance_windows', ['dst_overlap', 'dst_gap'])
            && Schema::hasTable('monitoring_maintenance_command_receipts'), 503, 'Maintenance save recovery needs an application update.');
    }

    private function binding(MonitoringMaintenanceCommandReceipt $receipt, User $actor, string $operation, ?int $target, string $hash): void
    {
        abort_unless($receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
        abort_unless($receipt->operation === $operation && $receipt->target_window_id === $target && hash_equals($receipt->request_hash, $hash), 409, 'This request identity already belongs to a different maintenance change.');
    }

    private function present(MonitoringMaintenanceCommandReceipt $receipt, User $actor, CurrentAuthorizationReads $reads, ?MonitoringMaintenanceWindow $window): array
    {
        abort_unless($receipt->status === 'committed' && $receipt->window_id && $receipt->committed_at && $receipt->after_snapshot && $receipt->after_scope, 503, 'The maintenance save is not confirmed.');
        foreach ([$receipt->before_scope, $receipt->after_scope] as $scope) {
            if ($scope !== null) {
                $this->access->scope($actor, $scope, $reads);
            }
        }
        if ($window === null || (int) $window->id !== $receipt->window_id) {
            $window = $reads->query(MonitoringMaintenanceWindow::query()->useWritePdo())->whereKey($receipt->window_id)->first();
        }
        abort_unless($window, 404, 'unavailable');
        $this->access->scope($actor, $window->only(['site_id', 'device_id', 'monitor_id']), $reads);
        $snapshot = $receipt->after_snapshot;
        $saved = ['id' => (int) $snapshot['id'], 'version' => (int) $snapshot['version'], 'state' => $snapshot['state']];
        abort_unless((int) $window->version >= $saved['version'], 503, 'The maintenance history needs review.');

        return [
            'window' => $saved,
            'current_window' => ['id' => (int) $window->id, 'version' => (int) $window->version, 'state' => $window->status],
            'receipt' => [
                'schema_version' => 1, 'owner' => 'Monitoring', 'request_uuid' => $receipt->request_uuid,
                'actor_id' => $receipt->actor_user_id, 'operation' => $receipt->operation,
                'target_window_id' => $receipt->target_window_id, 'request_hash' => $receipt->request_hash,
                'committed_at' => $receipt->committed_at->utc()->toIso8601String(),
                'scope' => ['site_id' => $receipt->after_scope['site_id'], 'device_id' => $receipt->after_scope['device_id'], 'monitor_id' => $receipt->after_scope['monitor_id'], 'monitor_device_id' => $receipt->after_scope['monitor_device_id']],
            ],
        ];
    }

    private function snapshot(MonitoringMaintenanceWindow $window): array
    {
        return MaintenanceConfiguration::snapshot($window);
    }
}
