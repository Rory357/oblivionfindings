<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCompetencyAssessment extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_competency_assessments';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'competency_id',
        'assessed_by',
        'performance_review_id',
        'assessed_level',
        'target_level',
        'assessment_date',
        'notes',
        'assessor_declared_at',
        'staff_acknowledged_at',
        'evidence_path',
    ];

    protected $casts = [
        'assessment_date' => 'date',
        'assessed_level' => 'integer',
        'target_level' => 'integer',
        'assessor_declared_at' => 'datetime',
        'staff_acknowledged_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(HrCompetency::class, 'competency_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceReview::class, 'performance_review_id');
    }
}
