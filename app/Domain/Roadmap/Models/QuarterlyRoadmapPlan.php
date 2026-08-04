<?php

namespace App\Domain\Roadmap\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuarterlyRoadmapPlan extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyStorageContext;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MANAGER_REVIEW = 'manager_review';

    public const STATUS_EXEC_REVIEW = 'exec_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'roadmap_quarterly_plans';

    protected $fillable = [
        'fiscal_year',
        'quarter',
        'status',
        'preset_profile',
        'generated_at',
        'generated_by',
        'approved_at',
        'approved_by',
        'published_at',
        'published_by',
        'closed_at',
        'snapshot_hash',
        'snapshot_payload',
        'revision_no',
        'change_summary',
        'source_filters',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
        'snapshot_payload' => 'array',
        'source_filters' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(QuarterlyRoadmapPlanItem::class, 'quarterly_plan_id')->orderBy('rank');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReportSnapshot::class, 'quarterly_plan_id');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }
}
