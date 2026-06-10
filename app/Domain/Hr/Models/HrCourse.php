<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Database\Factories\Hr\HrCourseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrCourse extends Model
{
    use AuditableChanges, HasFactory;

    protected static function newFactory()
    {
        return HrCourseFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'code',
        'description',
        'category',
        'delivery_method',
        'duration_hours',
        'provider',
        'cost',
        'is_mandatory',
        'compliance_requirement_id',
        'max_participants',
        'is_active',
    ];

    protected $casts = [
        'duration_hours' => 'decimal:1',
        'cost' => 'decimal:2',
        'is_mandatory' => 'boolean',
        'is_active' => 'boolean',
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

    public function complianceRequirement(): BelongsTo
    {
        return $this->belongsTo(HrComplianceRequirement::class, 'compliance_requirement_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMandatory(Builder $query): Builder
    {
        return $query->where('is_mandatory', true);
    }
}
