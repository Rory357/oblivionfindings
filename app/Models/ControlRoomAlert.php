<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlRoomAlert extends Model
{
    protected $fillable = [
        'source',
        'alert_type',
        'severity',
        'status',
        'asset_id',
        'fleet_signal_id',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'resolved_at',
        'resolved_by_user_id',
        'closed_at',
        'closed_by_user_id',
        'escalated_at',
        'escalated_by_user_id',
        'escalation_level',
        'assigned_to_user_id',
        'assigned_at',
        'assigned_by_user_id',
        'created_by_user_id',
        'context',
        'notes',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'escalated_at' => 'datetime',
        'assigned_at' => 'datetime',
        'context' => 'array',
        'escalation_level' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function fleetSignal(): BelongsTo
    {
        return $this->belongsTo(FleetSignal::class, 'fleet_signal_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Scope for open alerts.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope for unresolved alerts.
     */
    public function scopeUnresolved($query)
    {
        return $query->whereNotIn('status', ['resolved', 'closed']);
    }

    /**
     * Scope for high priority alerts.
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('severity', ['high', 'critical']);
    }

    /**
     * Scope for assigned to a specific user.
     */
    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }
}
