<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControlledDrugLossReport extends Model
{
    use Concerns\HasReferenceNumber;
    use SoftDeletes;

    public const REFERENCE_PREFIX = 'CDL';

    protected $table = 'controlled_drug_loss_reports';

    protected $fillable = [
        'reference_number',
        'client_id',
        'client_medication_id',
        'incident_id',
        'medication_name',
        'quantity_lost',
        'unit',
        'circumstances',
        'immediate_action_taken',
        'accountable_officer_name',
        'discovered_by',
        'discovered_at',
        'reported_to_police',
        'police_reference',
        'police_reported_at',
        'reported_to_pharmacy',
        'pharmacy_notified_at',
        'pharmacy_name',
        'reported_to_regulator',
        'regulator_name',
        'regulator_reference',
        'regulator_notified_at',
        'investigation_status',
        'investigation_notes',
        'resolved_by',
        'resolved_at',
        'resolution_outcome',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'police_reported_at' => 'datetime',
        'pharmacy_notified_at' => 'datetime',
        'regulator_notified_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reported_to_police' => 'boolean',
        'reported_to_pharmacy' => 'boolean',
        'reported_to_regulator' => 'boolean',
        'quantity_lost' => 'decimal:2',
    ];

    // ─── Relationships ──────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(ClientIncident::class, 'incident_id');
    }

    public function discoveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discovered_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(MedicationMarAttachment::class, 'attachable')
            ->latest('id');
    }

    // ─── Scopes ─────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('investigation_status', ['reported', 'investigating']);
    }

    public function scopeReported(Builder $query): Builder
    {
        return $query->where('investigation_status', 'reported');
    }

    public function scopeInvestigating(Builder $query): Builder
    {
        return $query->where('investigation_status', 'investigating');
    }
}
