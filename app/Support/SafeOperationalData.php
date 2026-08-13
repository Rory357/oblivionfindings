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
use App\Models\SiteHouseRoom;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\IntegrationDiscoveryException;
use App\Services\Integration\UnifiTransportConfigurationException;
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
        'site_id', 'device_id', 'integration_id', 'sync_log_id',
        'provider', 'status', 'action',
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

            return $deviceScope !== [] && $model->group()->withTrashed()->exists()
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
        foreach (['site_id', 'device_id'] as $field) {
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
            $exception instanceof UnifiTransportConfigurationException => 'transport_security_failure',
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
        if (! $device instanceof Device) {
            return [];
        }

        $siteIds = self::assignmentSiteIds($assignment);
        if ($siteIds === null) {
            return [];
        }

        $scope = [
            'device_id' => (int) $device->getKey(),
        ];
        if ($siteIds !== []) {
            $scope['site_ids'] = $siteIds;
            if (count($siteIds) === 1) {
                $scope['site_id'] = $siteIds[0];
            }
        }

        return $scope;
    }

    /** @return array<string, int|array<int, int>> */
    private static function relationshipScope(DeviceRelationship $relationship): array
    {
        $devices = collect([
            $relationship->parent()->withTrashed()->first(),
            $relationship->child()->withTrashed()->first(),
        ])->filter(fn ($device): bool => $device instanceof Device)->values();

        if ($devices->count() !== 2) {
            return [];
        }

        $deviceScopes = $devices->map(fn (Device $device): array => self::auditScope($device));
        if ($deviceScopes->contains(fn (array $scope): bool => ($scope['site_ids'] ?? []) === [])) {
            return [];
        }

        $scope = [
            'device_ids' => $devices->pluck('id')->map(fn ($id): int => (int) $id)->all(),
        ];
        $siteIds = $deviceScopes
            ->flatMap(fn (array $deviceScope): array => $deviceScope['site_ids'])
            ->unique()->values()->all();
        if ($siteIds !== []) {
            $scope['site_ids'] = $siteIds;
            if (count($siteIds) === 1) {
                $scope['site_id'] = $siteIds[0];
            }
        }

        return $scope;
    }

    /** @return array<int, int>|null */
    private static function assignmentSiteIds(DeviceAssignment $assignment): ?array
    {
        return match ($assignment->assignable_type) {
            DeviceAssignment::TARGET_SITE => self::siteTargetIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_ROOM => self::roomTargetIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_CLIENT => self::clientTargetIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_STAFF => self::staffTargetIds((int) $assignment->assignable_id),
            DeviceAssignment::TARGET_VEHICLE => self::vehicleTargetIds((int) $assignment->assignable_id),
            default => null,
        };
    }

    /** @return array<int, int>|null */
    private static function siteTargetIds(int $siteId): ?array
    {
        $siteIds = self::canonicalSiteIds([$siteId]);

        return $siteIds === [] ? null : $siteIds;
    }

    /** @return array<int, int>|null */
    private static function roomTargetIds(int $roomId): ?array
    {
        $siteId = SiteRoom::query()->whereKey($roomId)->value('site_id');
        if (! is_numeric($siteId)) {
            return null;
        }

        $siteIds = self::canonicalSiteIds([(int) $siteId]);

        return $siteIds === [] ? null : $siteIds;
    }

    /** @return array<int, int>|null */
    private static function clientTargetIds(int $clientId): ?array
    {
        $client = Client::withTrashed()->whereKey($clientId)->first(['site_id']);
        if (! $client instanceof Client) {
            return null;
        }

        return self::canonicalSiteIds([$client->site_id]);
    }

    /** @return array<int, int>|null */
    private static function staffTargetIds(int $userId): ?array
    {
        if (! User::query()->whereKey($userId)->exists()) {
            return null;
        }

        $siteIds = HrEmployeeProfile::query()
            ->where('user_id', $userId)
            ->get(['primary_site_id', 'secondary_site_ids'])
            ->flatMap(fn (HrEmployeeProfile $profile): array => array_merge(
                [$profile->primary_site_id],
                is_array($profile->secondary_site_ids) ? $profile->secondary_site_ids : [],
            ))->all();

        return self::canonicalSiteIds($siteIds);
    }

    /** @return array<int, int>|null */
    private static function vehicleTargetIds(int $assetId): ?array
    {
        $asset = Asset::query()->vehicles()->whereKey($assetId)->first([
            'id', 'site_id', 'room_id', 'home_site_id', 'client_id', 'primary_driver_user_id',
        ]);
        if (! $asset instanceof Asset) {
            return null;
        }

        $siteIds = [$asset->site_id, $asset->home_site_id];
        if (is_numeric($asset->room_id)) {
            $siteIds[] = SiteHouseRoom::query()->whereKey((int) $asset->room_id)->value('site_id');
        }
        if (is_numeric($asset->client_id)) {
            $siteIds[] = Client::withTrashed()->whereKey((int) $asset->client_id)->value('site_id');
        }
        if (is_numeric($asset->primary_driver_user_id)) {
            $siteIds = [
                ...$siteIds,
                ...(self::staffTargetIds((int) $asset->primary_driver_user_id) ?? []),
            ];
        }

        return self::canonicalSiteIds($siteIds);
    }

    /**
     * @param  array<int, mixed>  $siteIds
     * @return array<int, int>
     */
    private static function canonicalSiteIds(array $siteIds): array
    {
        $ids = collect($siteIds)
            ->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return Site::withTrashed()
            ->whereKey($ids->all())
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
