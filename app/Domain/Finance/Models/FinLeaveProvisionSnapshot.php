<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinLeaveProvisionSnapshot extends Model
{
    protected $table = 'fin_leave_provision_snapshots';

    protected $fillable = [
        'organization_id',
        'user_id',
        'leave_type',
        'balance_hours',
        'hourly_rate',
        'provision_amount',
        'snapshot_date',
        'journal_id',
    ];

    protected $casts = [
        'balance_hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'provision_amount' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where($query->qualifyColumn('organization_id'), $orgId);
    }

    /**
     * Get the most recent snapshot for a given employee + leave type.
     */
    public static function latestFor(int $orgId, int $userId, string $leaveType): ?self
    {
        return static::where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->orderByDesc('snapshot_date')
            ->first();
    }
}
