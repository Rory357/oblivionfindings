<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use Database\Factories\Hr\HrApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrApplication extends Model
{
    use AuditableChanges, HasFactory;

    protected static function newFactory()
    {
        return HrApplicationFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'candidate_id',
        'requisition_id',
        'job_posting_id',
        'interview_kit_id',
        'position_title',
        'position_role',
        'target_site_id',
        'cv_storage_path',
        'cv_original_name',
        'cover_letter',
        'answers',
        'screening_answers',
        'candidate_tracking_token',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'answers' => 'array',
        'screening_answers' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function targetSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'target_site_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(HrJobRequisition::class, 'requisition_id');
    }

    public function interviewKit(): BelongsTo
    {
        return $this->belongsTo(HrInterviewKit::class, 'interview_kit_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(HrInterview::class, 'application_id');
    }

    public function referenceChecks(): HasMany
    {
        return $this->hasMany(HrReferenceCheck::class, 'application_id');
    }

    public function offer(): HasOne
    {
        return $this->hasOne(HrOffer::class, 'application_id');
    }

    public function jobPosting(): BelongsTo
    {
        return $this->belongsTo(HrJobPosting::class, 'job_posting_id');
    }
}
