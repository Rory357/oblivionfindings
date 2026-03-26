<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationRefusalFollowup extends Model
{
    use HasFactory;

    protected $table = 'medication_refusal_followups';

    protected $fillable = [
        'client_id',
        'client_medication_administration_id',
        'reason_category',
        'detailed_reason',
        'client_capacity_at_time',
        'offered_alternative',
        'alternative_details',
        'gp_notification_required',
        'gp_notified_at',
        'gp_notified_by',
        'gp_response',
        'family_notified',
        'family_notified_at',
        'follow_up_action',
        'follow_up_due_at',
        'follow_up_completed_at',
        'follow_up_completed_by',
        'escalated_to_manager',
        'escalated_at',
        'created_by',
    ];

    protected $casts = [
        'offered_alternative' => 'boolean',
        'gp_notification_required' => 'boolean',
        'gp_notified_at' => 'datetime',
        'family_notified' => 'boolean',
        'family_notified_at' => 'datetime',
        'follow_up_due_at' => 'datetime',
        'follow_up_completed_at' => 'datetime',
        'escalated_to_manager' => 'boolean',
        'escalated_at' => 'datetime',
    ];

    // ─── Relationships ─────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'client_medication_administration_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function gpNotifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gp_notified_by');
    }

    public function followUpCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follow_up_completed_by');
    }

    // ─── Scopes ────────────────────────────────────────────

    /**
     * Follow-ups that have not been completed yet.
     */
    public function scopePending($query)
    {
        return $query->whereNull('follow_up_completed_at')
            ->whereNotNull('follow_up_due_at');
    }

    /**
     * Follow-ups where GP notification is required but not yet sent.
     */
    public function scopeRequiresGpNotification($query)
    {
        return $query->where('gp_notification_required', true)
            ->whereNull('gp_notified_at');
    }

    /**
     * Follow-ups that are past their due date and not completed.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNull('follow_up_completed_at')
            ->whereNotNull('follow_up_due_at')
            ->where('follow_up_due_at', '<', now());
    }
}
