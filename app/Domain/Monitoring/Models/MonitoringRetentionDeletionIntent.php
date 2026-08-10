<?php

namespace App\Domain\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

final class MonitoringRetentionDeletionIntent extends Model
{
    protected $table = 'monitoring_retention_deletion_intents';

    protected $fillable = [
        'intent_uuid',
        'job_reference',
        'series_id',
        'site_id',
        'device_id',
        'monitor_id',
        'policy_id',
        'policy_version',
        'policy_scope_kind',
        'policy_identity_key',
        'retention_days',
        'data_class',
        'retention_tier',
        'period_start',
        'period_end',
        'occupied_bucket_count',
        'rollup_evidence_sha256',
        'state',
        'delete_acknowledged_at',
        'completed_at',
    ];

    protected $casts = [
        'policy_version' => 'integer',
        'retention_days' => 'integer',
        'occupied_bucket_count' => 'integer',
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
        'delete_acknowledged_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $intent): void {
            if (! Str::isUuid((string) $intent->intent_uuid)
                || blank($intent->job_reference)
                || ! in_array($intent->retention_tier, ['raw', 'hourly', 'daily'], true)
                || ! in_array($intent->state, ['pending', 'delete_acknowledged', 'completed'], true)
                || $intent->period_start === null
                || $intent->period_end === null
                || ! $intent->period_start->lessThan($intent->period_end)
                || $intent->policy_version < 1
                || $intent->retention_days < 1
                || $intent->occupied_bucket_count < 1
                || preg_match('/^[a-f0-9]{64}$/', (string) $intent->policy_identity_key) !== 1
                || preg_match('/^[a-f0-9]{64}$/', (string) $intent->rollup_evidence_sha256) !== 1) {
                throw new \UnexpectedValueException('Monitoring retention deletion intent is invalid.');
            }
        });

        self::updating(function (self $intent): void {
            $changed = array_values(array_diff(array_keys($intent->getDirty()), ['updated_at']));
            if ($changed === [] || array_diff($changed, ['state', 'delete_acknowledged_at', 'completed_at']) !== []) {
                throw new \UnexpectedValueException('Monitoring retention deletion intent identity is immutable.');
            }
            $from = (string) $intent->getOriginal('state');
            $to = (string) $intent->state;
            if (($from === 'pending' && $to !== 'delete_acknowledged')
                || ($from === 'delete_acknowledged' && $to !== 'completed')
                || $from === 'completed'
                || ($to === 'delete_acknowledged' && $intent->delete_acknowledged_at === null)
                || ($to === 'completed' && ($intent->delete_acknowledged_at === null || $intent->completed_at === null))) {
                throw new \UnexpectedValueException('Monitoring retention deletion intent transition is invalid.');
            }
        });

        self::deleting(function (): void {
            throw new \UnexpectedValueException('Monitoring retention deletion intents cannot be deleted.');
        });
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'series_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionPolicy::class, 'policy_id');
    }
}
