<?php

namespace App\Domain\SecurityDevices\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceMaintenanceRecord extends Model
{
    use AuditableChanges;

    protected $table = 'device_maintenance_records';

    protected $fillable = [
        'device_id',
        'type',
        'status',
        'description',
        'scheduled_for',
        'completed_at',
        'performed_by_user_id',
        'vendor_reference',
        'cost',
        'notes',
    ];

    protected $casts = [
        'scheduled_for' => 'date',
        'completed_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForTenant(Builder $query, int $tenantId): Builder
    {
        return $query->whereHas('device', fn (Builder $deviceQuery) => $deviceQuery->forTenant($tenantId));
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', now()->toDateString());
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
