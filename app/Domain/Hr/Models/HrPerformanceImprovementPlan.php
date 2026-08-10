<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPerformanceImprovementPlan extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_performance_improvement_plans';

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'manager_user_id',
        'title',
        'reason',
        'expectations',
        'support_offered',
        'consequences',
        'status',
        'start_date',
        'end_date',
        'review_date',
        'outcome',
        'outcome_notes',
        'completed_at',
        'end_reminder_sent_at',
        'employee_acknowledged',
        'employee_acknowledged_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'review_date' => 'date',
        'completed_at' => 'datetime',
        'end_reminder_sent_at' => 'datetime',
        'employee_acknowledged' => 'boolean',
        'employee_acknowledged_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_user_id', 'user_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(HrPipMilestone::class, 'pip_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'in_progress']);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
