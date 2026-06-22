<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrJobRequisition extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'title',
        'slug',
        'position_role',
        'position_id',
        'site_id',
        'employment_type',
        'openings',
        'status',
        'summary',
        'description',
        'requirements',
        'responsibilities',
        'default_interview_kit_id',
        'hiring_manager_user_id',
        'posting_channels',
        'external_posting_status',
        'external_reference',
        'external_posted_at',
        'external_sync_at',
        'external_sync_error',
        'published_at',
        'closing_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'openings' => 'integer',
        'posting_channels' => 'array',
        'external_reference' => 'array',
        'published_at' => 'datetime',
        'closing_at' => 'date',
        'external_posted_at' => 'datetime',
        'external_sync_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function defaultInterviewKit(): BelongsTo
    {
        return $this->belongsTo(HrInterviewKit::class, 'default_interview_kit_id');
    }

    public function hiringManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hiring_manager_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(HrApplication::class, 'requisition_id');
    }
}

