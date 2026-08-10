<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItMajorIncident extends Model
{
    use HasFactory, WritesLegacyStorageContext;

    public const SEVERITIES = ['sev1', 'sev2', 'sev3', 'sev4'];

    protected $fillable = [
        'ticket_id', 'severity', 'impact_summary', 'commander_user_id',
        'communications_lead_user_id', 'target_update_minutes', 'declared_at',
        'next_update_due_at', 'restoration_summary', 'restored_at', 'root_cause_summary',
        'review_summary', 'reviewed_at', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected $casts = [
        'declared_at' => 'datetime',
        'next_update_due_at' => 'datetime',
        'restored_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'target_update_minutes' => 'integer',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ItTicket::class, 'ticket_id');
    }

    public function commander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commander_user_id');
    }

    public function communicationsLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'communications_lead_user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(ItMajorIncidentUpdate::class, 'major_incident_id')->latest('published_at')->latest('id');
    }

    public function updateState(): string
    {
        if (in_array($this->ticket?->workflow_state, ['restored', 'resolved', 'review', 'closed'], true)) {
            return 'not_required';
        }
        if ($this->next_update_due_at === null) {
            return 'not_scheduled';
        }

        return $this->next_update_due_at->isPast() ? 'overdue' : 'on_time';
    }
}
