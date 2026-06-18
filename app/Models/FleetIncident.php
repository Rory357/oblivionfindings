<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FleetIncident extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    /** Land Transport Act 1998 s22 — report an injury/fatal crash to Police within 24 hours. */
    public const POLICE_REPORT_WINDOW_HOURS = 24;

    public const TYPES = ['collision', 'damage', 'theft', 'vandalism', 'breakdown', 'near_miss', 'other'];

    public const SEVERITIES = ['minor', 'moderate', 'major', 'critical'];

    public const STATUSES = ['reported', 'investigating', 'resolved', 'closed'];

    public const DAMAGE_CLASSIFICATIONS = ['light', 'repairable', 'write_off'];

    /** Degree-of-harm vocab — matches NotifiableEventClassifier::HARM_*. */
    public const INJURY_SEVERITIES = ['none', 'first_aid', 'medical', 'hospitalisation', 'death'];

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'reported_by_user_id',
        'driver_user_id',
        'booking_id',
        'incident_type',
        'severity',
        'occurred_at',
        'location',
        'latitude',
        'longitude',
        'description',
        'damage_details',
        'police_notified',
        'police_reference',
        'insurance_claimed',
        'insurance_reference',
        'status',
        'resolution_notes',
        'resolved_at',

        // 3.1 Vehicle/asset identity & compliance (snapshots — PREP-LATER)
        'asset_category',
        'vehicle_rego_snapshot',
        'wof_status_snapshot',
        'wof_expiry_snapshot',
        'cof_status_snapshot',
        'cof_expiry_snapshot',
        'odometer_at_incident',
        'fuel_type_snapshot',

        // 3.2 Driver / operator (licence — PREP-LATER)
        'driver_licence_number',
        'driver_licence_class',
        'driver_licence_expiry',
        'driver_years_held',
        'driver_on_duty',
        'supervisor_user_id',

        // 3.3 People aboard
        'people_aboard',
        'people_aboard_count',
        'whanau_informed',

        // 3.4 Third party
        'third_party_involved',
        'third_parties',

        // 3.5 Witnesses
        'witnesses',
        'attending_officer',

        // 3.6 Scene & conditions
        'road_type',
        'weather',
        'lighting',
        'traffic_conditions',
        'speed_limit',
        'estimated_speed',
        'manoeuvre',
        'road_hazard',

        // 3.7 Damage, drivability & recovery (VOR)
        'damage_classification',
        'is_drivable',
        'tow_required',
        'tow_provider',
        'cargo_equipment_damage',
        'vehicle_off_road',
        'off_road_from',
        'off_road_to',
        'service_resumed_at',

        // 3.8 Police & regulatory (NZ)
        'injury_involved',
        'fatality_involved',
        'injury_severity',
        'police_report_due_at',
        'police_report_logged_at',
        'traffic_crash_report_reference',
        'is_notifiable',
        'worksafe_notification_status',
        'worksafe_notified_at',
        'worksafe_reference',
        'acc_claim_lodged',
        'acc_claim_reference',
        'breath_test_administered',
        'breath_test_result',
        'drug_test_administered',
        'drug_test_result',

        // 3.9 Insurance & cost
        'insurer_name',
        'insurance_excess',
        'insurance_amount_sought',
        'insurance_amount_approved',
        'insurance_claim_status',
        'repair_contractor',
        'actual_repair_cost',
        'total_incident_cost',

        // 3.10 Investigation & follow-up
        'assigned_to_user_id',
        'root_cause',
        'corrective_actions',
        'contributing_factors',
        'investigation_completed_at',

        // 3.12 Non-vehicle asset specifics
        'asset_serial_snapshot',
        'asset_condition_before',
        'asset_condition_after',
        'warranty_status',
        'replacement_cost',

        // Near-miss
        'potential_severity',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
        'damage_details' => 'array',
        'police_notified' => 'boolean',
        'insurance_claimed' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',

        'wof_expiry_snapshot' => 'date',
        'cof_expiry_snapshot' => 'date',
        'odometer_at_incident' => 'integer',
        'driver_licence_expiry' => 'date',
        'driver_years_held' => 'integer',
        'driver_on_duty' => 'boolean',

        'people_aboard' => 'array',
        'people_aboard_count' => 'integer',
        'whanau_informed' => 'boolean',

        'third_party_involved' => 'boolean',
        'third_parties' => 'array',

        'witnesses' => 'array',

        'speed_limit' => 'integer',
        'estimated_speed' => 'integer',

        'is_drivable' => 'boolean',
        'tow_required' => 'boolean',
        'vehicle_off_road' => 'boolean',
        'off_road_from' => 'date',
        'off_road_to' => 'date',
        'service_resumed_at' => 'date',

        'injury_involved' => 'boolean',
        'fatality_involved' => 'boolean',
        'police_report_due_at' => 'datetime',
        'police_report_logged_at' => 'datetime',
        'is_notifiable' => 'boolean',
        'worksafe_notified_at' => 'datetime',
        'acc_claim_lodged' => 'boolean',
        'breath_test_administered' => 'boolean',
        'drug_test_administered' => 'boolean',

        'insurance_excess' => 'decimal:2',
        'insurance_amount_sought' => 'decimal:2',
        'insurance_amount_approved' => 'decimal:2',
        'actual_repair_cost' => 'decimal:2',
        'total_incident_cost' => 'decimal:2',

        'contributing_factors' => 'array',
        'investigation_completed_at' => 'datetime',

        'replacement_cost' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FleetVehicleBooking::class, 'booking_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FleetIncidentAttachment::class, 'fleet_incident_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(FleetIncidentFollowup::class, 'fleet_incident_id');
    }

    /** Per-resident client incidents spawned by the transport cascade (Gap F1). */
    public function clientIncidents(): HasMany
    {
        return $this->hasMany(ClientIncident::class, 'fleet_incident_id');
    }

    /**
     * The governance HsEvent (CATEGORY_VEHICLE_INCIDENT) created by the observer.
     * Linked by idempotency key rather than an FK, so resolve on demand.
     */
    public function linkedHsEvent(): ?HsEvent
    {
        $key = HsEvent::buildIdempotencyKey(static::class, $this->getKey(), HsEvent::CATEGORY_VEHICLE_INCIDENT);

        return HsEvent::where('idempotency_key', $key)->first();
    }

    /* ------------------------------------------------------------------ */
    /*  Severity vocab (Gap F4 — map, don't migrate)                       */
    /* ------------------------------------------------------------------ */

    /**
     * Map FleetIncident severity (minor/moderate/major/critical) to the H&S /
     * client-incident vocab (low/medium/high/critical) used cross-module. Single
     * source of truth for the observer, the bridge, and the resident cascade.
     */
    public static function mapSeverityToHs(?string $severity): string
    {
        return match (strtolower(trim((string) $severity))) {
            'critical' => 'critical',
            'major' => 'high',
            'moderate' => 'medium',
            default => 'low',
        };
    }

    public function hsSeverity(): string
    {
        return static::mapSeverityToHs($this->severity);
    }

    /** Critical/major fleet incidents raise a Control Room alert + safeguarding cascade. */
    public function isHighSeverity(): bool
    {
        return in_array($this->severity, ['major', 'critical'], true);
    }

    /* ------------------------------------------------------------------ */
    /*  s22 Police-report duty + worklist helpers                          */
    /* ------------------------------------------------------------------ */

    /** A crash that injured/killed someone triggers the s22 24-hour Police-report duty. */
    public function requiresPoliceReport(): bool
    {
        return (bool) ($this->injury_involved || $this->fatality_involved);
    }

    public function hasLoggedPoliceReport(): bool
    {
        return $this->police_report_logged_at !== null
            || filled($this->traffic_crash_report_reference);
    }

    /** Outstanding s22 duty: injury/fatal crash, no TCR logged, not closed. */
    public function isPoliceReportDue(): bool
    {
        return $this->requiresPoliceReport()
            && ! $this->hasLoggedPoliceReport()
            && $this->status !== 'closed';
    }

    /** When the 24-hour window closes (stored at report; falls back to occurred_at + 24h). */
    public function policeReportDueAt(): ?CarbonInterface
    {
        if ($this->police_report_due_at) {
            return $this->police_report_due_at;
        }

        return $this->occurred_at?->copy()->addHours(self::POLICE_REPORT_WINDOW_HOURS);
    }

    /** Hours left before the s22 window closes (negative = overdue); null if N/A. */
    public function policeReportHoursRemaining(): ?float
    {
        if (! $this->isPoliceReportDue()) {
            return null;
        }

        $dueAt = $this->policeReportDueAt();

        return $dueAt ? round(now()->diffInMinutes($dueAt, false) / 60, 1) : null;
    }

    public function isOffRoad(): bool
    {
        return (bool) $this->vehicle_off_road && $this->service_resumed_at === null;
    }

    public function reference(): string
    {
        return 'FI-'.str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT);
    }

    public function isEquipment(): bool
    {
        return $this->asset_category === 'equipment'
            || $this->incident_type === 'theft' && $this->asset_category !== 'vehicle';
    }

    /* ------------------------------------------------------------------ */
    /*  Tab scopes                                                         */
    /* ------------------------------------------------------------------ */

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['reported', 'investigating']);
    }

    public function scopeUnderInvestigation(Builder $query): Builder
    {
        return $query->where('status', 'investigating');
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', 'closed');
    }

    public function scopeNearMisses(Builder $query): Builder
    {
        return $query->where('incident_type', 'near_miss');
    }

    /** Injury/fatal crashes inside the s22 window without a logged TCR. */
    public function scopePoliceReportDue(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $q) => $q->where('injury_involved', true)->orWhere('fatality_involved', true))
            ->whereNull('police_report_logged_at')
            ->where(fn (Builder $q) => $q->whereNull('traffic_crash_report_reference')->orWhere('traffic_crash_report_reference', ''))
            ->where('status', '!=', 'closed');
    }

    public function scopeInjuryAcc(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('injury_involved', true)
            ->orWhere('fatality_involved', true)
            ->orWhere('acc_claim_lodged', true));
    }

    public function scopeInsuranceClaims(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('insurance_claimed', true)
            ->orWhereNotNull('insurance_claim_status'));
    }

    public function scopeOffRoad(Builder $query): Builder
    {
        return $query->where('vehicle_off_road', true)->whereNull('service_resumed_at');
    }
}
