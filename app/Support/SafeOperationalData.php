<?php

namespace App\Support;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Domain\SecurityDevices\Models\DeviceGroupMember;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\IntegrationDiscoveryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * One deny-by-default boundary for audit and operational diagnostic output.
 *
 * Audit records retain useful changed-field names, bounded state values and
 * internal scope identifiers. Reusable provider material and free text never
 * crosses this boundary.
 */
final class SafeOperationalData
{
    private const SENSITIVE_KEY_PATTERN = '/(?:secret|token|credential|password|config|override|meta|payload|error|url|uri|host|endpoint|remote|target|external|command|frame|ack|imei|serial_number|ip_address|user_agent)/i';

    private const SAFE_VALUE_FIELDS = [
        'status', 'health_status', 'severity', 'state', 'current_state',
        'connection_state', 'is_active', 'is_enabled', 'parse_ok',
        'items_processed', 'items_created', 'items_updated', 'items_errored',
        'started_at', 'completed_at', 'last_seen_at', 'last_signal_at',
        'last_observation_at', 'last_tested_at', 'last_synced_at', 'rotated_at',
        'assigned_at', 'released_at', 'deleted_at',
    ];

    private const SAFE_LOG_FIELDS = [
        'tenant_id', 'organization_id', 'site_id', 'device_id',
        'integration_id', 'sync_log_id', 'provider', 'status', 'action',
        'integration_event_id', 'signal_id', 'alert_id', 'severity',
        'items_processed', 'items_created', 'items_updated', 'items_errored',
        'error_category', 'failure_category',
    ];

    /** @return array<int, string> */
    public static function auditFields(array $attributes): array
    {
        return collect(array_keys($attributes))
            ->filter(fn ($key): bool => is_string($key) && ! self::sensitiveKey($key))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public static function auditValues(array $attributes): array
    {
        return collect($attributes)
            ->only(self::SAFE_VALUE_FIELDS)
            ->reject(fn ($value, string $key): bool => self::sensitiveKey($key) || ! self::safeScalar($value))
            ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value)
            ->all();
    }

    /**
     * Sanitize caller-supplied metadata at the final persistence boundary.
     *
     * Protected-domain callers may only persist the structured audit contract;
     * arbitrary nested metadata is discarded rather than recursively guessed.
     *
     * @return array<string, mixed>
     */
    public static function auditMeta(array $meta, Model $model, ?array $trustedCanonicalScope = null): array
    {
        $safe = [];

        if (is_array($meta['fields'] ?? null)) {
            $safe['fields'] = collect($meta['fields'])
                ->filter(fn ($field): bool => is_string($field) && ! self::sensitiveKey($field))
                ->values()
                ->all();
        }

        foreach (['before', 'after', 'values'] as $key) {
            if (is_array($meta[$key] ?? null)) {
                $safe[$key] = self::auditValues($meta[$key]);
            }
        }

        // AuditLogger resolves this once at the final persistence boundary and
        // passes the trusted result. Direct callers omit it and retain the
        // deny-by-default canonical resolution here.
        $scope = $trustedCanonicalScope ?? self::auditScope($model);
        if ($scope !== []) {
            $safe['scope'] = $scope;
        }

        return $safe;
    }

    /** @return array<string, int|array<int, int>> */
    public static function auditScope(Model $model): array
    {
        if ($model instanceof DeviceAssignment) {
            return self::assignmentScope($model);
        }

        if ($model instanceof DeviceGroupMember) {
            $deviceScope = self::deviceRelationScope($model);
            $groupTenantId = $model->group()->withTrashed()->value('tenant_id');

            return is_numeric($groupTenantId)
                && (int) $groupTenantId === ($deviceScope['tenant_id'] ?? null)
                    ? $deviceScope
                    : [];
        }

        if ($model instanceof DeviceRelationship) {
            return self::relationshipScope($model);
        }

        if (self::hasCanonicalDeviceRelation($model)) {
            return self::deviceRelationScope($model);
        }

        $scope = [];
        foreach (['tenant_id', 'organization_id', 'site_id', 'device_id'] as $field) {
            $value = $model->getAttribute($field);
            if (is_numeric($value)) {
                $scope[$field] = (int) $value;
            }
        }

        if ($model instanceof Device) {
            $scope['device_id'] = (int) $model->getKey();
            $siteIds = $model->assignments()
                ->active()
                ->get()
                ->flatMap(fn (DeviceAssignment $assignment): array => self::assignmentSiteIds($assignment))
                ->unique()->values()->all();
            if ($siteIds !== []) {
                $scope['site_ids'] = $siteIds;
                if (count($siteIds) === 1) {
                    $scope['site_id'] = $siteIds[0];
                }
            }
        }

        if ($model instanceof DeviceGroup) {
            $scope['device_ids'] = $model->devices()->pluck('devices.id')
                ->map(fn ($id): int => (int) $id)->values()->all();
        }

        return $scope;
    }

    /** @return array<string, mixed> */
    public static function logContext(array $context): array
    {
        return collect($context)
            ->only(self::SAFE_LOG_FIELDS)
            ->reject(fn ($value): bool => ! self::safeScalar($value))
            ->all();
    }

    public static function failureSummary(): string
    {
        return 'Provider operation failed. Review the bounded diagnostic state and retry.';
    }

    public static function failureCategory(?\Throwable $exception = null): string
    {
        return match (true) {
            $exception instanceof IntegrationDiscoveryException => $exception->failureCategory(),
            $exception instanceof ConnectionException => 'connection_failure',
            $exception instanceof ValidationException => 'validation_failure',
            default => 'provider_failure',
        };
    }

    public static function serviceState(string $output, int $exitCode): string
    {
        $state = strtolower(trim($output));

        if ($exitCode === 0 && $state === 'active') {
            return 'active';
        }

        if ($state === 'inactive') {
            return 'inactive';
        }

        return 'unavailable';
    }

    public static function protectsRequestContext(?Model $model): bool
    {
        if ($model === null) {
            return false;
        }

        $class = $model::class;

        return $model instanceof Device
            || str_starts_with($class, 'App\\Models\\Integration\\')
            || str_starts_with($class, 'App\\Domain\\SecurityDevices\\Models\\');
    }

    private static function sensitiveKey(string $key): bool
    {
        return preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1;
    }

    private static function safeScalar(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof \DateTimeInterface || $value instanceof Carbon;
    }

    private static function hasCanonicalDeviceRelation(Model $model): bool
    {
        return str_starts_with($model::class, 'App\\Domain\\SecurityDevices\\Models\\')
            && is_numeric($model->getAttribute('device_id'))
            && method_exists($model, 'device');
    }

    /** @return array<string, int|array<int, int>> */
    private static function deviceRelationScope(Model $model): array
    {
        $device = $model->device()->withTrashed()->first();

        return $device instanceof Device ? self::auditScope($device) : [];
    }

    /** @return array<string, int|array<int, int>> */
    private static function assignmentScope(DeviceAssignment $assignment): array
    {
        $device = $assignment->device()->withTrashed()->first();
        if (! $device instanceof Device || ! is_numeric($device->tenant_id)) {
            return [];
        }

        $tenantId = (int) $device->tenant_id;
        if (! self::assignmentTargetMatchesTenant($assignment, $tenantId)) {
            return [];
        }

        $scope = [
            'tenant_id' => $tenantId,
            'device_id' => (int) $device->getKey(),
        ];
        $siteIds = self::assignmentSiteIds($assignment);
        if ($siteIds !== []) {
            $scope['site_ids'] = $siteIds;
            if (count($siteIds) === 1) {
                $scope['site_id'] = $siteIds[0];
            }
        }

        return $scope;
    }

    private static function assignmentTargetMatchesTenant(DeviceAssignment $assignment, int $tenantId): bool
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => Site::withTrashed()
                ->whereKey($assignment->assignable_id)->where('tenant_id', $tenantId)->exists(),
            DeviceAssignment::TARGET_ROOM => (function () use ($assignment, $tenantId): bool {
                $room = SiteRoom::query()->whereKey($assignment->assignable_id)->first(['tenant_id', 'site_id']);

                return $room
                    && is_numeric($room->tenant_id)
                    && (int) $room->tenant_id === $tenantId
                    && is_numeric($room->site_id)
                    && Site::withTrashed()->whereKey((int) $room->site_id)
                        ->where('tenant_id', $tenantId)->exists();
            })(),
            DeviceAssignment::TARGET_CLIENT => (function () use ($assignment, $tenantId): bool {
                $client = Client::withTrashed()->whereKey($assignment->assignable_id)
                    ->first(['organization_id', 'site_id']);

                return $client
                    && is_numeric($client->organization_id)
                    && (int) $client->organization_id === $tenantId
                    && (! is_numeric($client->site_id) || Site::withTrashed()
                        ->whereKey((int) $client->site_id)->where('tenant_id', $tenantId)->exists());
            })(),
            DeviceAssignment::TARGET_STAFF => User::query()
                ->whereKey($assignment->assignable_id)
                ->where(function ($organization) use ($tenantId): void {
                    $organization->where('organization_id', $tenantId);
                    if ($tenantId === 1) {
                        $organization->orWhereNull('organization_id');
                    }
                })->exists(),
            DeviceAssignment::TARGET_VEHICLE => (function () use ($assignment, $tenantId): bool {
                $asset = Asset::query()->find($assignment->assignable_id);

                return $asset instanceof Asset && self::vehicleAssetMatchesTenant($asset, $tenantId);
            })(),
            default => false,
        };
    }

    private static function vehicleAssetMatchesTenant(Asset $asset, int $tenantId): bool
    {
        $hasTenantEvidence = false;
        foreach ([$asset->site_id, $asset->home_site_id] as $siteId) {
            if ($siteId === null) {
                continue;
            }

            $hasTenantEvidence = true;
            if (! is_numeric($siteId) || ! Site::withTrashed()
                ->whereKey((int) $siteId)->where('tenant_id', $tenantId)->exists()) {
                return false;
            }
        }

        if ($asset->client_id !== null) {
            $hasTenantEvidence = true;
            $client = Client::withTrashed()->whereKey($asset->client_id)
                ->first(['organization_id', 'site_id']);
            if (! $client
                || ! is_numeric($client->organization_id)
                || (int) $client->organization_id !== $tenantId
                || ($client->site_id !== null && (! is_numeric($client->site_id) || ! Site::withTrashed()
                    ->whereKey((int) $client->site_id)->where('tenant_id', $tenantId)->exists()))) {
                return false;
            }
        }

        return $hasTenantEvidence;
    }

    /** @return array<string, int|array<int, int>> */
    private static function relationshipScope(DeviceRelationship $relationship): array
    {
        $devices = collect([
            $relationship->parent()->withTrashed()->first(),
            $relationship->child()->withTrashed()->first(),
        ])->filter(fn ($device): bool => $device instanceof Device)->values();

        $tenantIds = $devices->pluck('tenant_id')
            ->filter(fn ($tenantId): bool => is_numeric($tenantId))
            ->map(fn ($tenantId): int => (int) $tenantId)
            ->unique()->values();

        if ($devices->count() !== 2 || $tenantIds->count() !== 1) {
            return [];
        }

        $scope = [
            'tenant_id' => $tenantIds->first(),
            'device_ids' => $devices->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
        $siteIds = $devices
            ->flatMap(fn (Device $device): array => self::auditScope($device)['site_ids'] ?? [])
            ->unique()->values()->all();
        if ($siteIds !== []) {
            $scope['site_ids'] = $siteIds;
            if (count($siteIds) === 1) {
                $scope['site_id'] = $siteIds[0];
            }
        }

        return $scope;
    }

    /** @return array<int, int> */
    private static function assignmentSiteIds(DeviceAssignment $assignment): array
    {
        $tenantId = $assignment->device()->withTrashed()->value('tenant_id');
        if (! is_numeric($tenantId)) {
            return [];
        }

        $siteIds = match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => [(int) $assignment->assignable_id],
            DeviceAssignment::TARGET_ROOM => [(int) SiteRoom::query()
                ->whereKey($assignment->assignable_id)->where('tenant_id', (int) $tenantId)
                ->value('site_id')],
            DeviceAssignment::TARGET_CLIENT => [(int) Client::withTrashed()
                ->whereKey($assignment->assignable_id)->where('organization_id', (int) $tenantId)
                ->value('site_id')],
            DeviceAssignment::TARGET_STAFF => (array) HrEmployeeProfile::query()
                ->where('user_id', $assignment->assignable_id)->where('tenant_id', (int) $tenantId)
                ->get(['primary_site_id', 'secondary_site_ids'])
                ->flatMap(fn (HrEmployeeProfile $profile): array => array_merge(
                    [$profile->primary_site_id],
                    is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [],
                ))->all(),
            DeviceAssignment::TARGET_VEHICLE => (function () use ($assignment, $tenantId): array {
                $asset = Asset::query()->find($assignment->assignable_id);
                if (! $asset) {
                    return [];
                }

                $ids = [$asset->site_id, $asset->home_site_id];
                if ($asset->client_id) {
                    $ids[] = Client::withTrashed()
                        ->whereKey($asset->client_id)->where('organization_id', (int) $tenantId)
                        ->value('site_id');
                }

                return $ids;
            })(),
            default => [],
        };

        return Site::withTrashed()
            ->where('tenant_id', (int) $tenantId)
            ->whereIn('id', collect($siteIds)->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)->all())
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
