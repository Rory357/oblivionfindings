<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafeguardingTerminalTransition extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public const STATUS_APPLIED = 'applied';

    protected $fillable = [
        'idempotency_key',
        'safeguarding_concern_id',
        'hs_event_id',
        'control_room_alert_id',
        'site_id',
        'requested_by_user_id',
        'applied_by_user_id',
        'target_status',
        'status',
        'authority',
        'reason',
        'override_reason',
        'evidence_reference',
        'authority_snapshot',
        'evidence_snapshot',
        'request_hash',
        'evidence_hash',
        'provenance_hash',
        'attempt_count',
        'last_error_code',
        'requested_at',
        'last_attempted_at',
        'failed_at',
        'applied_at',
    ];

    protected $casts = [
        'authority_snapshot' => 'array',
        'evidence_snapshot' => 'array',
        'requested_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'failed_at' => 'datetime',
        'applied_at' => 'datetime',
        'attempt_count' => 'integer',
    ];

    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    public function healthSafetyEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function controlRoomAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'control_room_alert_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_user_id');
    }
}
