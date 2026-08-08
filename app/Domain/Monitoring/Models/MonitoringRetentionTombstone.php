<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class MonitoringRetentionTombstone extends Model
{
    protected $table = 'monitoring_retention_tombstones';

    protected $fillable = [
        'tombstone_uuid',
        'series_id',
        'snapshot_id',
        'deletion_intent_id',
        'site_id',
        'device_id',
        'monitor_id',
        'data_class',
        'retention_tier',
        'period_start',
        'period_end',
        'policy_id',
        'deleted_by_user_id',
        'job_reference',
        'deleted_at',
    ];

    protected $casts = [
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $tombstone): void {
            $hasSeries = $tombstone->series_id !== null;
            $hasSnapshot = $tombstone->snapshot_id !== null;
            if ($hasSeries === $hasSnapshot
                || ! Str::isUuid((string) $tombstone->tombstone_uuid)
                || blank($tombstone->job_reference)
                || $tombstone->period_start === null
                || $tombstone->period_end === null
                || $tombstone->period_start->greaterThan($tombstone->period_end)
                || $tombstone->deleted_at === null) {
                throw new \UnexpectedValueException(
                    'Monitoring retention tombstone evidence is incomplete.',
                );
            }

            if ($hasSnapshot
                && ($tombstone->monitor_id !== null
                    || $tombstone->data_class !== 'configuration'
                    || $tombstone->retention_tier !== 'configuration')) {
                throw new \UnexpectedValueException(
                    'Snapshot retention tombstone lineage is invalid.',
                );
            }
        });

        self::updating(function (): void {
            throw new \UnexpectedValueException('Monitoring retention tombstone evidence is immutable.');
        });

        self::deleting(function (): void {
            throw new \UnexpectedValueException(
                'Monitoring retention tombstone evidence cannot be deleted.',
            );
        });
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'series_id');
    }

    public function deletionIntent(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionDeletionIntent::class, 'deletion_intent_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'snapshot_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionPolicy::class, 'policy_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
