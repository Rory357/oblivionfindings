<?php

namespace App\Models;

use App\Contracts\Timeline\EmitsToTimeline;
use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $type e.g. injury, behaviour, medication, safeguarding, near_miss
 */
class ClientIncident extends Model implements EmitsToTimeline
{
    use AuditableChanges;
    use Concerns\HasReferenceNumber;
    use HasFactory;

    public const REFERENCE_PREFIX = 'INC';

    protected $fillable = [
        'reference_number',
        'client_id',
        'site_id',
        'hs_event_id',
        'reported_by',
        'shift_id',
        'control_room_alert_id',
        'fleet_incident_id', // originating fleet/asset incident (transport cascade — Gap F1)
        'respite_stay_id',
        'service_context_id',
        'template_id',

        'type',
        'source',   // manual|control_room|sensor|automated (interactive is derived)
        'severity', // low|medium|high
        'status',   // draft|submitted|reviewed|closed

        'occurred_at',
        'description',
        'metadata',

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

        'closed_outcome',
        'closed_notes',

        'reopened_by',
        'reopened_at',
        'reopened_reason',

        // Near-miss fields
        'potential_severity',
        'potential_consequence',

        // WorkSafe notification fields
        'is_notifiable',
        'worksafe_notification_status',
        'worksafe_notified_at',
        'worksafe_reference',
        'site_preserved',
        'site_preservation_released_at',
        'site_preservation_released_by',

        // Injury details
        'injured_person_name',
        'injured_person_role',
        'injured_person_age',
        'injury_body_part',
        'injury_nature',
        'injury_classification',
        'medical_treatment_type',

        // Investigation tracking (the incident's denormalised status; the full
        // investigation + root cause + corrective actions + lessons now live in the
        // H&S register — HsInvestigation / HsCorrectiveAction — per Option B).
        'investigation_status',
        'investigation_assigned_to',
        'investigation_started_at',
        'investigation_completed_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'submitted_at' => 'datetime',
        'metadata' => 'array',
        'requires_followup' => 'boolean',
        'portal_visible' => 'boolean',
        'reviewed_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'is_notifiable' => 'boolean',
        'worksafe_notified_at' => 'datetime',
        'site_preserved' => 'boolean',
        'site_preservation_released_at' => 'datetime',
        'injured_person_age' => 'integer',
        'investigation_started_at' => 'datetime',
        'investigation_completed_at' => 'datetime',
    ];

    /**
     * `interactive` is derived from `source` (see accessor) and surfaced to the
     * UI without a stored column, so it can never drift from the source.
     */
    protected $appends = [
        'interactive',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function controlRoomAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'control_room_alert_id');
    }

    public function respiteStay(): BelongsTo
    {
        return $this->belongsTo(RespiteStay::class, 'respite_stay_id');
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(IncidentTemplate::class, 'template_id');
    }

    /**
     * The originating fleet/asset incident, when this client incident was created
     * by the transport-incident cascade (residents aboard). Reverse of
     * FleetIncident::clientIncidents() — Gap F1.
     */
    public function fleetIncident(): BelongsTo
    {
        return $this->belongsTo(FleetIncident::class, 'fleet_incident_id');
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investigation_assigned_to');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ClientIncidentAttachment::class, 'incident_id');
    }

    /**
     * Safeguarding concern(s) spawned from this incident (reverse of
     * SafeguardingConcern.related_incident_id) — e.g. abuse/neglect auto-escalation.
     */
    public function safeguardingConcerns(): HasMany
    {
        return $this->hasMany(SafeguardingConcern::class, 'related_incident_id');
    }

    public function restraintEvents(): HasMany
    {
        return $this->hasMany(RestraintEvent::class, 'related_incident_id');
    }

    /** First-aid treatments escalated to / linked with this incident. */
    public function firstAidRecords(): HasMany
    {
        return $this->hasMany(FirstAidRecord::class, 'related_incident_id');
    }

    /**
     * Staff workplace injuries that arose from this incident (e.g. a worker injured
     * during a client fall/transfer or behavioural escalation). Reverse of
     * WorkplaceInjury::relatedIncident(). Injuries & RTW redesign — cross-module seam 1.
     */
    public function workplaceInjuries(): HasMany
    {
        return $this->hasMany(WorkplaceInjury::class, 'related_incident_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(IncidentFollowup::class, 'client_incident_id');
    }

    /**
     * The governance HsEvent this incident converged into. Prefer the explicit
     * journey link, while retaining the idempotency lookup for legacy rows.
     */
    public function linkedHsEvent(): ?HsEvent
    {
        if ($this->hs_event_id !== null) {
            $direct = $this->relationLoaded('hsEvent')
                ? $this->getRelation('hsEvent')
                : $this->hsEvent()->first();

            if ($direct !== null) {
                return $direct;
            }
        }

        $category = $this->type === 'near_miss'
            ? HsEvent::CATEGORY_NEAR_MISS
            : HsEvent::CATEGORY_INCIDENT;

        $key = HsEvent::buildIdempotencyKey(static::class, $this->getKey(), $category);

        return HsEvent::where('idempotency_key', $key)->first();
    }

    /**
     * Corrective actions raised against the linked HsEvent (empty collection if none).
     *
     * @return Collection<int, HsCorrectiveAction>
     */
    public function linkedCorrectiveActions(): Collection
    {
        return $this->linkedHsEvent()?->correctiveActions()->with('assignedTo:id,name')->get() ?? collect();
    }

    public function isShiftLinked(): bool
    {
        return ! empty($this->shift_id);
    }

    /**
     * Interactive = a human raised it (staff report or operator flag). Sensor /
     * automated detections are non-interactive. Derived from source, never stored.
     */
    public function getInteractiveAttribute(): bool
    {
        return in_array($this->source, ['manual', 'control_room'], true);
    }

    public function getCategoryAttribute(): ?string
    {
        return $this->type;
    }

    public function setCategoryAttribute(?string $value): void
    {
        $this->attributes['type'] = $value;
    }

    public function isSubmitted(): bool
    {
        return ! empty($this->submitted_at) || $this->status !== 'draft';
    }

    public function isEditableByReporter(User $user): bool
    {
        if ((int) $this->reported_by !== (int) $user->id) {
            return false;
        }

        // Shift-linked incidents: editable until the shift ends.
        if ($this->isShiftLinked()) {
            $shift = $this->shift;
            if (! $shift) {
                return false;
            }

            return ! $shift->isEnded();
        }

        // Standalone incidents: editable until explicitly submitted.
        return empty($this->submitted_at) && $this->status === 'draft';
    }

    /**
     * @return array<string, mixed>
     */
    public function toTimelineEvent(): array
    {
        $this->loadMissing('client');

        return [
            'type' => 'incident',
            'occurred_at' => $this->occurred_at ?? $this->created_at ?? now(),
            'actor_user_id' => $this->reported_by,
            'client_id' => $this->client_id,
            'shift_id' => $this->shift_id,
            'site_id' => $this->client?->site_id,
            'subject' => 'Incident: '.($this->title ?? $this->type),
            'body' => $this->description,
            'meta' => array_filter([
                'severity' => $this->severity,
                'status' => $this->status,
                'requires_followup' => $this->requires_followup,
            ], fn ($value) => $value !== null && $value !== ''),
            'visibility' => 'internal',
            'is_pinned' => false,
            'created_by' => $this->reported_by,
        ];
    }
}
