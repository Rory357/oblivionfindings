<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded service: when it happened, the observed odometer, the work and
 * evidence behind it, and how the schedule's next due point moved. Completing
 * a service never releases a maintenance hold or approves Finance.
 */
class FleetServiceCompletion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'schedule_id', 'asset_id', 'completed_on', 'odometer_observation_id', 'odometer_km', 'work_order_id',
        'provider', 'evidence_reference', 'evidence_document_ids', 'notes', 'previous_next_due_at',
        'previous_next_due_km', 'next_due_at', 'next_due_km', 'schedule_version', 'recorded_by_user_id',
        'request_key', 'request_fingerprint', 'created_at',
    ];

    protected $casts = [
        'completed_on' => 'date',
        'odometer_km' => 'decimal:1',
        'evidence_document_ids' => 'array',
        'previous_next_due_at' => 'date',
        'previous_next_due_km' => 'decimal:1',
        'next_due_at' => 'date',
        'next_due_km' => 'decimal:1',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Service history is retained as recorded.'));
        static::deleting(fn () => throw new \LogicException('Service history is retained as recorded.'));
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FleetServiceSchedule::class, 'schedule_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(FleetWorkOrder::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
