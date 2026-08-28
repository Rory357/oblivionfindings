<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientMedicationAdministration extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    public const ADMINISTRATION_ONLY_EVIDENCE_FIELDS = [
        'blood_glucose_level',
        'pulse_bpm',
        'blood_pressure_systolic',
        'blood_pressure_diastolic',
        'insulin_units_given',
        'injection_site',
        'inhaler_technique_observed',
        'spacer_used',
        'peak_flow_before',
        'peak_flow_after',
        'topical_area',
        'topical_skin_condition',
    ];

    protected $fillable = [
        'corrected_of_id',
        'is_correction',
        'client_request_uuid',
        'client_id',
        'client_medication_id',
        'shift_id',
        'medication_round_id',
        'service_context_id',
        'administered_by',
        'witnessed_by',
        'scheduled_for',
        'administered_at',
        'status',
        'reason',
        'reason_code',
        'correction_reason',
        'correction_requested_by',
        'dose_given',
        'notes',
        'review_required',
        'review_reason',
        'review_flagged_at',
        'review_flagged_by',
        'blood_glucose_level',
        'pulse_bpm',
        'blood_pressure_systolic',
        'blood_pressure_diastolic',
        'insulin_units_given',
        'injection_site',
        'inhaler_technique_observed',
        'spacer_used',
        'peak_flow_before',
        'peak_flow_after',
        'topical_area',
        'topical_skin_condition',
        'correction_status',
        'correction_approved_by',
        'correction_approved_at',
        'correction_rejection_reason',
        'witnessed_at',
        'witness_method',
    ];

    protected $casts = [
        'scheduled_for' => 'datetime',
        'administered_at' => 'datetime',
        'is_correction' => 'boolean',
        'review_required' => 'boolean',
        'review_flagged_at' => 'datetime',
        'blood_glucose_level' => 'decimal:1',
        'pulse_bpm' => 'integer',
        'blood_pressure_systolic' => 'integer',
        'blood_pressure_diastolic' => 'integer',
        'insulin_units_given' => 'decimal:1',
        'inhaler_technique_observed' => 'boolean',
        'spacer_used' => 'boolean',
        'peak_flow_before' => 'integer',
        'peak_flow_after' => 'integer',
        'correction_approved_at' => 'datetime',
        'witnessed_at' => 'datetime',
    ];

    /**
     * Restrict a clinical reader to the one administration row that currently
     * represents each recorded event.
     *
     * Raw correction and audit readers must deliberately omit this scope so
     * pending, rejected, superseded, and original rows remain available as
     * immutable evidence. For legacy originals with more than one approved
     * child, the latest approval timestamp wins; the highest id is the stable
     * tie-breaker (and fallback for legacy approvals without a timestamp).
     */
    public function scopeEffectiveClinicalEvidence(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(function (Builder $effective) use ($table): void {
            $effective
                ->where(function (Builder $original) use ($table): void {
                    $original
                        ->where(function (Builder $notCorrection) use ($table): void {
                            $notCorrection->where($table.'.is_correction', false)
                                ->orWhereNull($table.'.is_correction');
                        })
                        ->whereNotExists(function ($approvedCorrection) use ($table): void {
                            $approvedCorrection
                                ->selectRaw('1')
                                ->from($table.' as approved_correction')
                                ->whereColumn('approved_correction.corrected_of_id', $table.'.id')
                                ->whereColumn('approved_correction.client_id', $table.'.client_id')
                                ->where(function ($medication) use ($table): void {
                                    $medication
                                        ->whereColumn('approved_correction.client_medication_id', $table.'.client_medication_id')
                                        ->orWhere(function ($bothNull) use ($table): void {
                                            $bothNull->whereNull('approved_correction.client_medication_id')
                                                ->whereNull($table.'.client_medication_id');
                                        });
                                })
                                ->where('approved_correction.is_correction', true)
                                ->where('approved_correction.correction_status', 'approved')
                                ->whereNull('approved_correction.deleted_at');
                        });
                })
                ->orWhere(function (Builder $approvedCorrection) use ($table): void {
                    $approvedCorrection
                        ->where($table.'.is_correction', true)
                        ->where($table.'.correction_status', 'approved')
                        ->whereNotNull($table.'.corrected_of_id')
                        ->whereExists(function ($original) use ($table): void {
                            $original
                                ->selectRaw('1')
                                ->from($table.' as corrected_original')
                                ->whereColumn('corrected_original.id', $table.'.corrected_of_id')
                                ->whereColumn('corrected_original.client_id', $table.'.client_id')
                                ->where(function ($medication) use ($table): void {
                                    $medication
                                        ->whereColumn('corrected_original.client_medication_id', $table.'.client_medication_id')
                                        ->orWhere(function ($bothNull) use ($table): void {
                                            $bothNull->whereNull('corrected_original.client_medication_id')
                                                ->whereNull($table.'.client_medication_id');
                                        });
                                })
                                ->where(function ($notCorrection): void {
                                    $notCorrection->where('corrected_original.is_correction', false)
                                        ->orWhereNull('corrected_original.is_correction');
                                })
                                ->whereNull('corrected_original.deleted_at');
                        })
                        ->where($table.'.id', '=', function ($winner) use ($table): void {
                            $winner
                                ->select('approved_winner.id')
                                ->from($table.' as approved_winner')
                                ->whereColumn('approved_winner.corrected_of_id', $table.'.corrected_of_id')
                                ->whereColumn('approved_winner.client_id', $table.'.client_id')
                                ->where(function ($medication) use ($table): void {
                                    $medication
                                        ->whereColumn('approved_winner.client_medication_id', $table.'.client_medication_id')
                                        ->orWhere(function ($bothNull) use ($table): void {
                                            $bothNull->whereNull('approved_winner.client_medication_id')
                                                ->whereNull($table.'.client_medication_id');
                                        });
                                })
                                ->where('approved_winner.is_correction', true)
                                ->where('approved_winner.correction_status', 'approved')
                                ->whereNull('approved_winner.deleted_at')
                                ->orderByDesc('approved_winner.correction_approved_at')
                                ->orderByDesc('approved_winner.id')
                                ->limit(1);
                        });
                });
        });
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function medication()
    {
        // Administrations are immutable historical evidence. Resolve the
        // narrow legacy case where their parent order was soft-deleted before
        // medication deletion was retired, without widening any live-order
        // query to include deleted medications.
        return $this->belongsTo(ClientMedication::class, 'client_medication_id')->withTrashed();
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function serviceContext()
    {
        return $this->belongsTo(ServiceContext::class);
    }

    public function administeredBy()
    {
        return $this->belongsTo(User::class, 'administered_by');
    }

    public function witnessedBy()
    {
        return $this->belongsTo(User::class, 'witnessed_by');
    }

    public function correctionRequestedBy()
    {
        return $this->belongsTo(User::class, 'correction_requested_by');
    }

    public function reviewFlaggedBy()
    {
        return $this->belongsTo(User::class, 'review_flagged_by');
    }

    public function round()
    {
        return $this->belongsTo(MedicationRound::class, 'medication_round_id');
    }

    public function prnEffectiveness()
    {
        return $this->hasOne(MedicationPrnEffectiveness::class, 'client_medication_administration_id');
    }

    public function attachments()
    {
        return $this->hasMany(MedicationMarAttachment::class, 'client_medication_administration_id')
            ->latest('id');
    }
}
