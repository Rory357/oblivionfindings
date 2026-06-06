<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteStay extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_id',
        'client_id',
        'status',
        'actual_start',
        'actual_end',
        'arrival_checklist',
        'arrival_checklist_complete',
        'admission_risk_screen',
        'discharge_summary',
        'discharge_reason',
        'discharge_medication_reconciliation',
        'discharge_checklist',
        'discharge_checklist_complete',
        'post_respite_summary',
        'transport_arrangements',
        'absence_records',
        'bed_hold_status',
        'bed_hold_reason',
        'bed_hold_until',
        'cultural_support_notes',
        'evidence_pack_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'actual_start' => 'datetime',
        'actual_end' => 'datetime',
        'arrival_checklist' => 'array',
        'arrival_checklist_complete' => 'boolean',
        'admission_risk_screen' => 'array',
        'discharge_medication_reconciliation' => 'array',
        'discharge_checklist' => 'array',
        'discharge_checklist_complete' => 'boolean',
        'transport_arrangements' => 'array',
        'absence_records' => 'array',
        'bed_hold_until' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(RespiteBooking::class, 'booking_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function evidencePack(): BelongsTo
    {
        return $this->belongsTo(RespiteEvidencePack::class, 'evidence_pack_id');
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(RespiteHandoverNote::class, 'stay_id');
    }

    public function communications(): HasMany
    {
        return $this->hasMany(RespiteCommunicationLog::class, 'stay_id');
    }

    public function dailyNotes(): HasMany
    {
        return $this->hasMany(RespiteDailyNote::class, 'stay_id');
    }

    public function riskPlanActivations(): HasMany
    {
        return $this->hasMany(RespiteRiskPlanActivation::class, 'stay_id');
    }

    public function medicationReconciliations(): HasMany
    {
        return $this->hasMany(RespiteMedicationReconciliation::class, 'stay_id');
    }

    public function restraintEvents(): HasMany
    {
        return $this->hasMany(RestraintEvent::class, 'stay_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(ClientIncident::class, 'respite_stay_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
