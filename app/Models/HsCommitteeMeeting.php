<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsCommitteeMeeting extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    protected $fillable = [
        'hs_committee_id',
        'scheduled_at',
        'started_at',
        'ended_at',
        'location',
        'status',
        'attendees',
        'agenda_items',
        'minutes',
        'action_items',
        'safety_concerns_raised',
        'confirmed_attendees',
        'actions_due_count',
        'minutes_document_path',
        'minutes_document_name',
        'recorded_by',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'attendees' => 'array',
        'agenda_items' => 'array',
        'action_items' => 'array',
        'confirmed_attendees' => 'array',
        'actions_due_count' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function committee(): BelongsTo
    {
        return $this->belongsTo(HsCommittee::class, 'hs_committee_id');
    }

    /**
     * Real attendee model (RSVP + attendance). Named `attendeeUsers` so it does
     * not shadow the legacy `attendees` JSON-cast attribute. Source of truth for
     * attendance going forward; backfilled from the JSON columns by migration.
     */
    public function attendeeUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'hs_meeting_attendees', 'meeting_id', 'user_id')
            ->withPivot(['response', 'attended'])
            ->withTimestamps();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(HsAttachment::class, 'attachable');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeUpcoming($query)
    {
        return $query->where('scheduled_at', '>=', now())
            ->where('status', 'scheduled');
    }
}
