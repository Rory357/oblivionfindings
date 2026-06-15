<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrDevelopmentGoal extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'manager_user_id',
        'hr_goal_id',
        'title',
        'description',
        'category',
        'competency_area',
        'target_level',
        'current_level',
        'status',
        'progress_percent',
        'start_date',
        'due_date',
        'completed_at',
        'review_frequency',
        'review_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'current_level' => 'integer',
        'progress_percent' => 'integer',
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** Optional OKR objective this development plan rolls up into. */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'hr_goal_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['not_started', 'in_progress', 'blocked']);
    }
}
