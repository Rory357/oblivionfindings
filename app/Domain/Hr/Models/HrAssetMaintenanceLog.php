<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maintenance / repair job against an HR equipment asset. Mirrors the canonical
 * asset_maintenance_logs column shape so a future unification is a straight copy.
 */
class HrAssetMaintenanceLog extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'type',
        'vendor',
        'cost',
        'sent_at',
        'expected_back_at',
        'completed_at',
        'next_due_at',
        'outcome',
        'notes',
        'performed_by',
        'attachments',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'sent_at' => 'date',
        'expected_back_at' => 'date',
        'completed_at' => 'date',
        'next_due_at' => 'date',
        'attachments' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(HrAsset::class, 'asset_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
}
