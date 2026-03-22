<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSuccessionCandidate extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'succession_plan_id',
        'employee_profile_id',
        'readiness',
        'development_needs',
        'strengths',
        'overall_rating',
        'assessed_by',
        'assessed_at',
    ];

    protected $casts = [
        'overall_rating' => 'integer',
        'assessed_at' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function successionPlan(): BelongsTo
    {
        return $this->belongsTo(HrSuccessionPlan::class, 'succession_plan_id');
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
