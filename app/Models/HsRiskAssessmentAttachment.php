<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Risk Assessments redesign — supporting evidence attached to a risk assessment
 * (SWMS, method statements, hazard photos, SDS sheets, site plans, PDFs).
 * Mirrors EmergencyDrillAttachment / FleetIncidentAttachment / SafeguardingAttachment.
 */
class HsRiskAssessmentAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'hs_risk_assessment_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
        'kind',
        'notes',
        'alt_text',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function riskAssessment(): BelongsTo
    {
        return $this->belongsTo(HsRiskAssessment::class, 'hs_risk_assessment_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
