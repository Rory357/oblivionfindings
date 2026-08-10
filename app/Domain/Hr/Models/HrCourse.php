<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Database\Factories\Hr\HrCourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCourse extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrCourseFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'code',
        'description',
        'learning_outcomes',
        'prerequisites',
        'category',
        'delivery_method',
        'duration_hours',
        'provider',
        'provider_reference',
        'cost',
        'org_pays_provider',
        'staff_can_claim',
        'is_mandatory',
        'requires_renewal',
        'validity_period_months',
        'renewal_reminder_months',
        'requires_assessment',
        'pass_mark_percentage',
        'cpd_points',
        'mandatory_for_roles',
        'compliance_requirement_id',
        'max_participants',
        'is_active',
    ];

    protected $hidden = ['application_code_key'];

    protected $casts = [
        'duration_hours' => 'decimal:1',
        'cost' => 'decimal:2',
        'prerequisites' => 'array',
        'mandatory_for_roles' => 'array',
        'is_mandatory' => 'boolean',
        'requires_renewal' => 'boolean',
        'requires_assessment' => 'boolean',
        'org_pays_provider' => 'boolean',
        'staff_can_claim' => 'boolean',
        'is_active' => 'boolean',
        'validity_period_months' => 'integer',
        'renewal_reminder_months' => 'integer',
        'pass_mark_percentage' => 'integer',
        'cpd_points' => 'integer',
        'max_participants' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function sessions(): HasMany
    {
        return $this->hasMany(HrCourseSession::class, 'course_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(HrCourseEnrollment::class, 'course_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(HrCourseAssignment::class, 'hr_course_id');
    }

    public function complianceRequirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'compliance_requirement_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }
}
