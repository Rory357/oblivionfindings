<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ClientIncident extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'reported_by',
        'shift_id',
        'template_id',

        'type',
        'severity', // low|medium|high
        'status',   // draft|submitted|reviewed|closed

        'occurred_at',
        'description',

        'requires_followup',
        'immediate_action_taken',
        'witnesses',

        // legacy compatibility (kept for existing UI/db)
        'location',
        'title',
        'immediate_action',
        'follow_up_required',

        'submitted_at',

        'reviewed_by',
        'reviewed_at',
        'review_notes',

        'portal_visible',

        'closed_by',
        'closed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'submitted_at' => 'datetime',
        'requires_followup' => 'boolean',
        'portal_visible' => 'boolean',
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(IncidentTemplate::class, 'template_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientIncidentAttachment::class, 'incident_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(IncidentFollowup::class, 'client_incident_id');
    }

    public function isShiftLinked(): bool
    {
        return !empty($this->shift_id);
    }

    public function isSubmitted(): bool
    {
        return !empty($this->submitted_at) || $this->status !== 'draft';
    }

    public function isEditableByReporter(User $user): bool
    {
        if ((int)$this->reported_by !== (int)$user->id) {
            return false;
        }

        // Shift-linked incidents: editable until the shift ends.
        if ($this->isShiftLinked()) {
            $shift = $this->shift;
            if (!$shift) {
                return false;
            }
            return !$shift->isEnded();
        }

        // Standalone incidents: editable until explicitly submitted.
        return empty($this->submitted_at) && $this->status === 'draft';
    }
}
