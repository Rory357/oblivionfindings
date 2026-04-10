<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lone Worker Alert — legacy domain record.
 *
 * @deprecated PR4: Lone worker operational alerts now flow through the canonical
 *             signal pipeline via LoneWorkerSignalService → SignalProcessingService
 *             → ControlRoomAlert.
 *
 *             This model is RETAINED for:
 *             - backward compatibility with the lone worker UI during transition
 *             - reading historical alert data
 *
 *             The canonical operational lifecycle (triage, SLA, escalation,
 *             playbooks) lives on ControlRoomAlert where source='lone_worker'.
 *
 * @see \App\Services\HealthSafety\LoneWorkerSignalService — canonical signal emission
 * @see \App\Models\ControlRoomAlert — canonical operational alert
 */
class LoneWorkerAlert extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'lone_worker_session_id',
        'alert_type',
        'triggered_at',
        'acknowledged_at',
        'acknowledged_by',
        'escalated_at',
        'escalated_to',
        'resolved_at',
        'resolution_notes',
        'status',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // Relationships

    public function session(): BelongsTo
    {
        return $this->belongsTo(LoneWorkerSession::class, 'lone_worker_session_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function escalatedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_to');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeUnacknowledged($query)
    {
        return $query->whereNull('acknowledged_at');
    }

    // Helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }
}
