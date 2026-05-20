<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class IncidentGovernanceEscalation extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_incident_id', 'notifiable_incident_id', 'risk_register_entry_id',
        'escalation_reason', 'status', 'notified_chair', 'notified_ceo',
        'chair_notified_at', 'ceo_notified_at', 'acknowledged_at',
        'acknowledged_by', 'action_taken',
    ];

    protected $casts = [
        'chair_notified_at' => 'datetime',
        'ceo_notified_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function notifiableIncident(): BelongsTo
    {
        return $this->belongsTo(NotifiableIncident::class);
    }

    public function riskEntry(): BelongsTo
    {
        return $this->belongsTo(RiskRegisterEntry::class, 'risk_register_entry_id');
    }

    public function notifiedChairUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_chair');
    }

    public function notifiedCeoUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notified_ceo');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function acknowledge(int $userId, ?string $action = null): void
    {
        $this->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
            'action_taken' => $action,
        ]);
    }
}
