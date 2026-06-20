<?php

namespace App\Domain\Clinical\Models;

use App\Domain\Clinical\Enums\ClinicalAssessmentType;
use App\Domain\Clinical\Enums\ClinicalRiskBand;
use App\Models\Client;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A completed standardised clinical risk assessment (FRAT / Braden / MUST /
 * IDDSI). Stores the clinician's structured inputs alongside the transparent
 * computed total, risk band and component breakdown (see the matching scorer in
 * Services\Assessments). Evidence attaches via the polymorphic
 * `clinical_attachments` morph.
 */
class ClinicalRiskAssessment extends Model
{
    use AuditableChanges, SoftDeletes;

    protected $table = 'clinical_risk_assessments';

    protected $fillable = [
        'organization_id',
        'client_id',
        'assessed_by',
        'assessment_type',
        'assessed_at',
        'inputs',
        'total_score',
        'risk_band',
        'breakdown',
        'summary',
        'advice',
        'meta',
        'tool_version',
        'notes',
        'review_due_at',
    ];

    protected $casts = [
        'assessment_type' => ClinicalAssessmentType::class,
        'risk_band' => ClinicalRiskBand::class,
        'assessed_at' => 'datetime',
        'review_due_at' => 'date',
        'inputs' => 'array',
        'breakdown' => 'array',
        'meta' => 'array',
        'total_score' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(ClinicalAttachment::class, 'attachable')->latest();
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeOfType($query, ClinicalAssessmentType $type)
    {
        return $query->where('assessment_type', $type);
    }

    public function scopeReviewDue($query)
    {
        return $query->whereNotNull('review_due_at')->whereDate('review_due_at', '<=', now());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function needsAction(): bool
    {
        return $this->risk_band?->needsAction() ?? false;
    }

    public function isReviewDue(): bool
    {
        return $this->review_due_at !== null && $this->review_due_at->isToday()
            || ($this->review_due_at !== null && $this->review_due_at->isPast());
    }
}
