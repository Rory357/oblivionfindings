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

class HrEngagementSurvey extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'survey_type',
        'status',
        'is_anonymous',
        'audience_type',
        'audience_site_ids',
        'starts_at',
        'ends_at',
        'created_by',
        'updated_by',
        'published_by',
        'published_at',
        'closed_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'audience_site_ids' => 'array',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function questions(): HasMany
    {
        return $this->hasMany(HrEngagementSurveyQuestion::class, 'survey_id')->orderBy('sort_order');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(HrEngagementSurveyResponse::class, 'survey_id');
    }

    public function actionPlans(): HasMany
    {
        return $this->hasMany(HrEngagementActionPlan::class, 'survey_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
