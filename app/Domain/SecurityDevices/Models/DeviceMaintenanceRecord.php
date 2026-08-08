<?php

namespace App\Domain\SecurityDevices\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
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

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            $originalStatus = (string) $record->getRawOriginal('status');
            if (in_array($originalStatus, ['completed', 'cancelled'], true) && $record->isDirty()) {
                throw new \UnexpectedValueException('Completed and cancelled Device maintenance evidence is immutable.');
            }

            if ($record->status === 'completed'
                && ($record->completed_at === null || ! is_numeric($record->performed_by_user_id))) {
                throw new \UnexpectedValueException('Completed Device maintenance requires time and performer evidence.');
            }
            if ($record->status !== 'completed'
                && ($record->completed_at !== null || $record->performed_by_user_id !== null)) {
                throw new \UnexpectedValueException('Non-completed Device maintenance cannot retain completion evidence.');
            }
        });
    }

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
