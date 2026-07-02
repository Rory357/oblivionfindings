<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestraintEvent extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;
    use Concerns\HasReferenceNumber;

    public const REFERENCE_PREFIX = 'RST';

    protected $fillable = [
        'reference_number',
        'stay_id',
        'client_id',
        'behaviour_support_plan_id',
        'site_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'restraint_type',
        'severity',
        'trigger_description',
        'de_escalation_attempted',
        'restraint_description',
        'staff_involved',
        'person_response',
        'post_incident_support',
        'injury_occurred',
        'injury_details',
        'within_support_plan',
        'deviation_reason',
        'authorised_by',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'lessons_learned',
        'related_incident_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'duration_minutes' => 'integer',
        'staff_involved' => 'array',
        'injury_occurred' => 'boolean',
        'within_support_plan' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function stay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'stay_id');
    }

    public function behaviourSupportPlan(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlan::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function authorisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function relatedIncident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'related_incident_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RestraintEventAttachment::class, 'restraint_event_id');
    }
}
