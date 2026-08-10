<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrBenefitEnrollment extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'benefit_plan_id',
        'enrollment_date',
        'status',
        'employee_contribution_rate',
        'employer_contribution_rate',
        'opt_out_date',
        'notes',
    ];

    protected $casts = [
        'enrollment_date' => 'date',
        'employee_contribution_rate' => 'decimal:2',
        'employer_contribution_rate' => 'decimal:2',
        'opt_out_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function benefitPlan(): BelongsTo
    {
        return $this->belongsTo(HrBenefitPlan::class, 'benefit_plan_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
