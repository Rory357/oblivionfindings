<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\ServiceContext;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayRateRule extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'priority',
        'position_role',
        'site_id',
        'service_context_id',
        'applies_on_public_holiday',
        'applies_on_sleepover',
        'applies_on_call',
        'regular_multiplier',
        'overtime_multiplier',
        'public_holiday_multiplier',
        'sleepover_flat_rate',
        'on_call_hourly_rate',
        'effective_from',
        'effective_to',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
        'applies_on_public_holiday' => 'boolean',
        'applies_on_sleepover' => 'boolean',
        'applies_on_call' => 'boolean',
        'regular_multiplier' => 'decimal:2',
        'overtime_multiplier' => 'decimal:2',
        'public_holiday_multiplier' => 'decimal:2',
        'sleepover_flat_rate' => 'decimal:2',
        'on_call_hourly_rate' => 'decimal:2',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function serviceContext(): BelongsTo
    {
        return $this->belongsTo(ServiceContext::class);
    }
}

