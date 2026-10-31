<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\Site;
use Database\Factories\Hr\HrApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrApplication extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrApplicationFactory::new();
    }

    /**
     * Work-readiness answers from the public apply wizard (all optional).
     * Labels live in resources/js/lib/job-posting-constants.ts.
     */
    public const YEARS_OF_EXPERIENCE = ['none', 'under_1', '1_to_3', '3_plus'];

    public const RIGHT_TO_WORK_STATUSES = ['citizen_or_resident', 'work_visa', 'not_yet'];

    public const DRIVER_LICENCE_CLASSES = ['full', 'restricted', 'none'];

    protected $fillable = [
        'tenant_id',
        'candidate_id',
        'requisition_id',
        'interview_kit_id',
        'position_title',
        'position_role',
        'target_site_id',
        'cv_storage_path',
        'cv_original_name',
        'cover_letter',
        'experience_summary',
        'years_of_experience',
        'right_to_work_status',
        'driver_licence_class',
        'screening_answers',
        'candidate_tracking_token',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'screening_answers' => 'array',
        'experience_summary' => 'string',
        'years_of_experience' => 'string',
        'right_to_work_status' => 'string',
        'driver_licence_class' => 'string',
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
}
