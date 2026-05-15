<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteDamage extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'site_id',
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

    protected static function booted(): void
    {
        static::creating(function (self $damage): void {
            if ($damage->tenant_id === null && $damage->site_id !== null) {
                $damage->tenant_id = Site::query()
                    ->whereKey($damage->site_id)
                    ->value('tenant_id');
            }
        });
    }

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
