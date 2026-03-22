<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCompetencyAssessment extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'hr_competency_assessments';

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'competency_id',
        'assessor_user_id',
        'performance_review_id',
        'proficiency_level',
        'target_level',
        'assessment_date',
        'notes',
    ];

    protected $casts = [
        'assessment_date' => 'date',
        'proficiency_level' => 'integer',
        'target_level' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_user_id', 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(HrCompetency::class, 'competency_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_user_id');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceReview::class, 'performance_review_id');
    }
}
