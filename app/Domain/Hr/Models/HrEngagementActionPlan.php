<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEngagementActionPlan extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'survey_id',
        'tenant_id',
        'owner_user_id',
        'title',
        'description',
        'priority',
        'status',
        'progress_percent',
        'due_date',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'progress_percent' => 'integer',
        'due_date' => 'date',
        'completed_at' => 'date',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(HrEngagementSurvey::class, 'survey_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }
}
