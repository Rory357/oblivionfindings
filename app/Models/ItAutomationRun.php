<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItAutomationRun extends Model
{
    use HasFactory;

    public const STATUSES = ['running', 'succeeded', 'failed', 'skipped'];

    protected $fillable = [
        'tenant_id', 'automation_key', 'schedule_expression', 'status', 'started_at',
        'finished_at', 'runtime_ms', 'error_summary', 'result_summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'runtime_ms' => 'integer',
        'result_summary' => 'array',
    ];

    public function scopeForTenantOrSystem(Builder $query, int $tenantId): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->whereNull('tenant_id')
            ->orWhere('tenant_id', $tenantId));
    }
}
