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
    protected $dateFormat = 'Y-m-d H:i:s.u';

    use AuditableChanges, HasFactory, SoftDeletes;
    use Concerns\HasReferenceNumber;

    public const REFERENCE_PREFIX = 'RST';

    public const LEGACY_FACTUAL_FIELDS = ['started_at', 'ended_at', 'duration_minutes', 'restraint_type', 'severity', 'trigger_description', 'de_escalation_attempted', 'restraint_description', 'staff_involved', 'person_response', 'post_incident_support', 'injury_occurred', 'injury_details', 'within_support_plan', 'deviation_reason', 'authorised_by', 'behaviour_support_plan_id', 'related_incident_id'];

    protected $fillable = [
        'lock_version', 'source_revision', 'source_schema_version', 'creation_key', 'creation_fingerprint',
        'recorded_client_id', 'recorded_site_id', 'recorded_stay_id', 'behaviour_support_plan_version_id',
        'plan_authorization_state', 'plan_authorization_snapshot', 'review_owner_id', 'review_due_at',
        'review_assignment_revision', 'reviewed_source_revision',
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
        'lock_version' => 'integer', 'source_revision' => 'integer', 'source_schema_version' => 'integer',
        'review_assignment_revision' => 'integer', 'reviewed_source_revision' => 'integer',
        'plan_authorization_snapshot' => 'array', 'review_due_at' => 'immutable_datetime',
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

    public function reviewOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'review_owner_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlanVersion::class, 'behaviour_support_plan_version_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(RestraintEventReview::class, 'restraint_event_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(RestraintMonitoringObservation::class, 'restraint_event_id');
    }

    public function debriefs(): HasMany
    {
        return $this->hasMany(RestraintDebrief::class, 'restraint_event_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(RestraintFollowup::class, 'restraint_event_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(RestraintSourceDelivery::class, 'restraint_event_id');
    }

    public function needsReview(): bool
    {
        return $this->reviewed_source_revision === null || $this->reviewed_source_revision < $this->source_revision;
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(RestraintEventAttachment::class, 'restraint_event_id');
    }
}
