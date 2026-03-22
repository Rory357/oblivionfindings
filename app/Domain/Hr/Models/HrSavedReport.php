<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSavedReport extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'report_type',
        'fields',
        'filters',
        'group_by',
        'sort_by',
        'sort_direction',
        'is_scheduled',
        'schedule_frequency',
        'schedule_recipients',
        'last_run_at',
        'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'filters' => 'array',
        'schedule_recipients' => 'array',
        'is_scheduled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

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

    public function scopeOfType(Builder $query, string $reportType): Builder
    {
        return $query->where('report_type', $reportType);
    }
}
