<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringRetentionPolicy extends Model
{
    protected $table = 'monitoring_retention_policies';

    protected $fillable = [
        'name',
        'scope_kind',
        'site_id',
        'device_id',
        'data_class',
        'privacy_class',
        'identity_key',
        'raw_days',
        'hourly_days',
        'daily_days',
        'legal_hold',
        'is_active',
        'created_by_user_id',
        'version',
        'change_reason',
        'updated_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'raw_days' => 'integer',
        'hourly_days' => 'integer',
        'daily_days' => 'integer',
        'legal_hold' => 'boolean',
        'is_active' => 'boolean',
        'version' => 'integer',
        'deactivated_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $policy): void {
            $policy->identity_key = self::identityFor(
                (string) $policy->scope_kind,
                $policy->site_id === null ? null : (int) $policy->site_id,
                $policy->device_id === null ? null : (int) $policy->device_id,
                $policy->data_class === null ? null : (string) $policy->data_class,
                $policy->privacy_class === null ? null : (string) $policy->privacy_class,
            );
        });
    }

    public static function identityFor(
        string $scopeKind,
        ?int $siteId,
        ?int $deviceId,
        ?string $dataClass,
        ?string $privacyClass,
    ): string {
        return hash('sha256', implode('|', [
            strtolower(trim($scopeKind)),
            $siteId === null ? '*' : (string) $siteId,
            $deviceId === null ? '*' : (string) $deviceId,
            $dataClass === null ? '*' : strtolower(trim($dataClass)),
            $privacyClass === null ? '*' : strtolower(trim($privacyClass)),
        ]));
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }
}
