<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteDamage extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyStorageContext;

    protected $fillable = [
        'reported_by',
        'assigned_to',
        'title',
        'description',
        'location_in_site',
        'severity',
        'status',
        'damage_date',
        'discovered_date',
        'estimated_cost',
        'actual_cost',
        'insurance_claim_ref',
        'insurance_status',
        'repair_notes',
        'repaired_at',
        'repaired_by',
        'photos',
        'checklist_run_id',
    ];

    protected $casts = [
        'damage_date' => 'date',
        'discovered_date' => 'date',
        'estimated_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'repaired_at' => 'datetime',
        'photos' => 'array',
    ];

    // Relationships

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function repairedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repaired_by');
    }

    public function checklistRun(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistRun::class, 'checklist_run_id');
    }

    // Scopes

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['closed', 'repaired']);
    }

    public function scopeBySeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeBySite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
