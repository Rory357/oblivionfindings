<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrExitInterview extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrExitInterviewFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'interviewer_user_id',
        'interview_date',
        'departure_reason',
        'would_recommend',
        'overall_satisfaction',
        'what_went_well',
        'what_could_improve',
        'management_feedback',
        'culture_feedback',
        'additional_comments',
        'is_confidential',
        'created_by',
    ];

    protected $casts = [
        'interview_date' => 'date',
        'would_recommend' => 'boolean',
        'overall_satisfaction' => 'integer',
        'is_confidential' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
