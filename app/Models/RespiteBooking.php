<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RespiteBooking extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_request_id',
        'client_id',
        'start_at',
        'end_at',
        'status',
        'funding_source',
        'funding_reference',
        'service_agreement_id',
        'funding_status',
        'agreement_status',
        'consent_authority',
        'consent_authority_name',
        'consent_authority_contact',
        'consent_authority_evidence',
        'family_portal_consent_bound_at',
        'family_portal_consent_bound_by',
        'funding_approved_ref',
        'funding_approved_at',
        'assigned_coordinator_id',
        'location_id',
        'cancellation_reason',
        'cancellation_source',
        'cancellation_notice_hours',
        'approvals',
        'readiness_override_reason',
        'capacity_override_reason',
        'cultural_snapshot',
        'cultural_placement_check',
        'setting_restriction',
        'interpreter_arranged',
        'copayment_amount',
        'copayment_basis',
        'private_pay_portion',
        'copayment_status',
        'recurrence_rule',
        'series_id',
        'funding_expiry_acknowledged_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'funding_approved_at' => 'datetime',
        'consent_authority_evidence' => 'array',
        'family_portal_consent_bound_at' => 'datetime',
        'approvals' => 'array',
        'cultural_snapshot' => 'array',
        'cultural_placement_check' => 'array',
        'interpreter_arranged' => 'boolean',
        'copayment_amount' => 'decimal:2',
        'private_pay_portion' => 'decimal:2',
        'funding_expiry_acknowledged_at' => 'datetime',
        'eligibility_checks' => 'array',
        'consent_records' => 'array',
        'funding_verification' => 'array',
        'pre_arrival_checklist' => 'array',
        'medications_reconciled' => 'boolean',
        'medications_reconciled_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(RespiteBookingRequest::class, 'booking_request_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_coordinator_id');
    }

    public function serviceAgreement(): BelongsTo
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_id');
    }

    /**
     * The home/site this respite bed sits in. Often null today (approve() doesn't
     * set it), so consumers fall back to the client's home site_id.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'location_id');
    }

    public function stays(): HasMany
    {
        return $this->hasMany(RespiteStay::class, 'booking_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RespiteResourceAllocation::class, 'booking_id');
    }

    public function shift()
    {
        return $this->hasOne(Shift::class, 'respite_booking_id');
    }

    /**
     * Typed pre-stay readiness contract for the workspace ring and detail views.
     *
     * @return array{score:int,ready:bool,segments:array<int,array{key:string,label:string,status:string,complete:bool,message:string|null}>}
     */
    public function readiness(): array
    {
        $segments = [
            $this->fundingReadinessSegment(),
            $this->readinessSegment(
                'eligibility',
                'Eligibility checked',
                ! empty($this->eligibility_checks),
                'Confirm eligibility and placement checks.'
            ),
            $this->agreementReadinessSegment(),
            $this->readinessSegment(
                'consent',
                'Consent recorded',
                ! empty($this->consent_records) || filled($this->consent_authority),
                'Record who may legally consent under PPPR / HDC Right 7.'
            ),
            $this->interpreterReadinessSegment(),
            $this->culturalPlacementReadinessSegment(),
            $this->settingRestrictionReadinessSegment(),
            $this->readinessSegment(
                'pre_arrival',
                'Pre-arrival checklist',
                ! empty($this->pre_arrival_checklist),
                'Complete the receiving-home pre-arrival checklist.'
            ),
            $this->readinessSegment(
                'medication_reconciliation',
                'Medicines reconciled',
                (bool) $this->medications_reconciled,
                'Complete admission medication reconciliation where required.'
            ),
        ];

        $complete = collect($segments)->where('complete', true)->count();

        return [
            'score' => (int) round($complete / count($segments) * 100),
            'ready' => $complete === count($segments),
            'segments' => $segments,
        ];
    }

    private function fundingReadinessSegment(): array
    {
        $status = $this->funding_status ?: ($this->funding_source ? 'pending_approval' : 'not_required');
        $complete = in_array($status, ['approved', 'not_required'], true);

        return [
            'key' => 'funding',
            'label' => 'Funding approved',
            'status' => $complete ? 'complete' : 'attention',
            'complete' => $complete,
            'message' => match ($status) {
                'approved' => $this->funding_approved_ref
                    ? 'Approved: '.$this->funding_approved_ref
                    : 'Funding approval recorded.',
                'not_required' => 'No funder approval required.',
                'declined' => 'Funding has been declined.',
                'expired' => 'Funding approval has expired.',
                default => 'Funding approval is still pending.',
            },
        ];
    }

    private function agreementReadinessSegment(): array
    {
        $signed = in_array($this->agreement_status, ['signed', 'waived'], true)
            || (bool) $this->serviceAgreement?->signed_at
            || (bool) $this->serviceAgreement?->signed_date;

        return $this->readinessSegment(
            'service_agreement',
            'Placement agreement signed',
            $signed,
            'Send and capture the signed placement agreement.'
        );
    }

    private function interpreterReadinessSegment(): array
    {
        $snapshot = $this->cultural_snapshot ?? [];
        $required = (bool) ($snapshot['interpreter_required'] ?? false);

        return $this->readinessSegment(
            'interpreter',
            'Interpreter arranged',
            ! $required || (bool) $this->interpreter_arranged,
            'Arrange the requested interpreter before arrival.'
        );
    }

    private function culturalPlacementReadinessSegment(): array
    {
        $snapshot = $this->cultural_snapshot ?? [];
        $requiresPlacementCheck = (bool) ($snapshot['is_maori'] ?? false)
            || filled($snapshot['iwi'] ?? null)
            || filled($snapshot['hapu'] ?? null)
            || filled($snapshot['marae'] ?? null)
            || filled($snapshot['cultural_considerations'] ?? null)
            || filled($snapshot['cultural_dietary_needs'] ?? null);

        return $this->readinessSegment(
            'cultural_placement',
            'Cultural placement checked',
            ! $requiresPlacementCheck || ! empty($this->cultural_placement_check),
            'Confirm cultural, whānau and dietary placement support before arrival.'
        );
    }

    private function settingRestrictionReadinessSegment(): array
    {
        $restriction = $this->setting_restriction ?: 'none';

        return $this->readinessSegment(
            'setting_restriction',
            'Restrictive setting authorised',
            $restriction === 'none' || (bool) data_get($this->consent_authority_evidence, 'setting_restriction_authorised'),
            'Record BSP and consent authority evidence for the restrictive setting.'
        );
    }

    private function readinessSegment(string $key, string $label, bool $complete, string $message): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $complete ? 'complete' : 'pending',
            'complete' => $complete,
            'message' => $complete ? null : $message,
        ];
    }
}
