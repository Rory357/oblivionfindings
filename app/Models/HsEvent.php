<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsEvent extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $table = 'hs_events';

    /* ------------------------------------------------------------------ */
    /*  Constants */
    /* ------------------------------------------------------------------ */

    // Event categories — maps to source model types
    public const CATEGORY_INCIDENT = 'incident';

    public const CATEGORY_NEAR_MISS = 'near_miss';

    public const CATEGORY_HAZARD = 'hazard';

    public const CATEGORY_INJURY = 'injury';

    public const CATEGORY_EXPOSURE = 'exposure';

    public const CATEGORY_RESTRAINT = 'restraint';

    public const CATEGORY_SAFEGUARDING = 'safeguarding';

    public const CATEGORY_DRILL_FAILURE = 'drill_failure';

    public const CATEGORY_INSPECTION_FAILURE = 'inspection_failure';

    public const CATEGORY_EQUIPMENT_FAULT = 'equipment_fault';

    public const CATEGORY_VEHICLE_INCIDENT = 'vehicle_incident';

    // Normalised severity levels
    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    // Lifecycle statuses
    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CORRECTIVE_ACTION = 'corrective_action';

    public const STATUS_MONITORING = 'monitoring';

    public const STATUS_CLOSED = 'closed';

    // WorkSafe notification statuses
    public const WORKSAFE_PENDING = 'pending';

    public const WORKSAFE_NOTIFIED = 'notified';

    public const WORKSAFE_ACKNOWLEDGED = 'acknowledged';

    // H&S handover acceptance states
    public const HANDOVER_NOT_READY = 'not_ready';

    public const HANDOVER_AWAITING_ACCEPTANCE = 'awaiting_acceptance';

    public const HANDOVER_ACCEPTED = 'accepted';

    public const HANDOVER_NOT_REQUIRED = 'not_required';

    /* ------------------------------------------------------------------ */
    /*  Fillable / Casts */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'organization_id',
        'reference_number',
        'source_type',
        'source_id',
        'event_category',
        'severity',
        'status',
        'occurred_at',
        'reported_at',
        'site_id',
        'client_id',
        'staff_id',
        'asset_id',
        'shift_id',
        'worksafe_notifiable',
        'worksafe_status',
        'worksafe_reference',
        'worksafe_notified_at',
        'worksafe_method',
        'worksafe_acknowledged_at',
        'worksafe_site_preserved',
        'investigation_required',
        'control_room_alert_id',
        'handover_status',
        'owner_user_id',
        'accepted_by_user_id',
        'accepted_at',
        'acceptance_notes',
        'closed_at',
        'closed_by',
        'closure_summary',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'reported_at' => 'datetime',
        'closed_at' => 'datetime',
        'worksafe_notifiable' => 'boolean',
        'worksafe_notified_at' => 'datetime',
        'worksafe_acknowledged_at' => 'datetime',
        'worksafe_site_preserved' => 'boolean',
        'investigation_required' => 'boolean',
        'accepted_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function controlRoomAlert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'control_room_alert_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Incident linked through the explicit journey FK. The polymorphic source
     * relation remains available for provenance and legacy event sources.
     */
    public function clientIncident(): HasOne
    {
        return $this->hasOne(ClientIncident::class, 'hs_event_id');
    }

    /**
     * All investigations for this event (supports re-investigation in future).
     */
    public function investigations(): HasMany
    {
        return $this->hasMany(HsInvestigation::class, 'hs_event_id');
    }

    /**
     * The latest/primary investigation — most recent by creation.
     */
    public function latestInvestigation(): HasOne
    {
        return $this->hasOne(HsInvestigation::class, 'hs_event_id')->latestOfMany();
    }

    /**
     * The active (non-completed) investigation, if one exists.
     */
    public function activeInvestigation(): HasOne
    {
        return $this->hasOne(HsInvestigation::class, 'hs_event_id')
            ->whereNotIn('status', [HsInvestigation::STATUS_COMPLETED])
            ->latestOfMany();
    }

    /**
     * All corrective actions for this event.
     */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(HsCorrectiveAction::class, 'hs_event_id');
    }

    /**
     * Open (unresolved) corrective actions.
     */
    public function openCorrectiveActions(): HasMany
    {
        return $this->hasMany(HsCorrectiveAction::class, 'hs_event_id')
            ->whereNotIn('status', [HsCorrectiveAction::STATUS_VERIFIED, HsCorrectiveAction::STATUS_CLOSED]);
    }

    /**
     * Risk assessments linked to this event.
     */
    public function riskAssessments(): HasMany
    {
        return $this->hasMany(HsRiskAssessment::class, 'hs_event_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeOpen($query)
    {
        return $query->where('status', '!=', self::STATUS_CLOSED);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeHighOrCritical($query)
    {
        return $query->whereIn('severity', [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]);
    }

    public function scopeOfCategory($query, string $category)
    {
        return $query->where('event_category', $category);
    }

    public function scopeWorksafeNotifiable($query)
    {
        return $query->where('worksafe_notifiable', true);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    public function isCritical(): bool
    {
        return $this->severity === self::SEVERITY_CRITICAL;
    }

    public function isHighOrCritical(): bool
    {
        return in_array($this->severity, [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL], true);
    }

    /**
     * Whether this event can have an investigation created for it.
     * Event must be open and not already have an active investigation.
     */
    public function canCreateInvestigation(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        return ! $this->activeInvestigation()->exists();
    }

    /**
     * Whether every recommendation has a coherent outcome and every resulting
     * corrective action is verified or closed.
     */
    public function allCorrectiveActionsResolved(): bool
    {
        if ($this->hasOpenCorrectiveActions()) {
            return false;
        }

        $investigations = $this->investigations()
            ->where('status', HsInvestigation::STATUS_COMPLETED)
            ->with(['recommendationDispositions.correctiveAction'])
            ->get();

        foreach ($investigations as $investigation) {
            $dispositions = $investigation->recommendationDispositions->keyBy('recommendation_index');

            foreach (array_keys($investigation->recommendations ?? []) as $index) {
                $disposition = $dispositions->get($index);
                if (! $disposition) {
                    return false;
                }

                if ($disposition->disposition === HsRecommendationDisposition::DISPOSITION_CORRECTIVE_ACTION) {
                    $action = $disposition->correctiveAction;
                    if (! $action || ! in_array($action->status, [
                        HsCorrectiveAction::STATUS_VERIFIED,
                        HsCorrectiveAction::STATUS_CLOSED,
                    ], true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether this event has any open corrective actions.
     */
    public function hasOpenCorrectiveActions(): bool
    {
        return $this->openCorrectiveActions()->exists();
    }

    /**
     * Whether this event has a completed investigation.
     */
    public function hasCompletedInvestigation(): bool
    {
        return $this->investigations()
            ->where('status', HsInvestigation::STATUS_COMPLETED)
            ->exists();
    }

    /**
     * Generate the idempotency key for a given source.
     * Format: sha256(SourceType:SourceId:EventCategory)
     */
    public static function buildIdempotencyKey(string $sourceType, int|string $sourceId, string $eventCategory): string
    {
        return hash('sha256', "{$sourceType}:{$sourceId}:{$eventCategory}");
    }

    /**
     * Generate a sequential reference number: HS-YYYY-NNNN
     */
    public static function generateReferenceNumber(): string
    {
        return app(ReferenceNumberGenerator::class)->next('HS');
    }
}
