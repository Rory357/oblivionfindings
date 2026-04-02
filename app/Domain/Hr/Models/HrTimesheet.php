<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrTimesheet extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'period_start',
        'period_end',
        'status',
        'total_hours',
        'submitted_at',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'returned_by',
        'returned_at',
        'returned_notes',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_hours' => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(HrTimeEntry::class, 'hr_timesheet_id');
    }

    /**
     * Legacy relationship: entries matched by user_id + date range (no FK).
     */
    public function entriesByDateRange(): HasMany
    {
        return $this->hasMany(HrTimeEntry::class, 'user_id', 'user_id')
            ->whereBetween('entry_date', [$this->period_start, $this->period_end]);
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'submitted');
    }

    public function scopeForPeriod($query, string $start, string $end)
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }

    public function scopeForTeam($query, int $managerUserId)
    {
        $teamUserIds = \App\Domain\Hr\Models\HrEmployeeProfile::where('manager_user_id', $managerUserId)
            ->where('is_active', true)
            ->pluck('user_id');

        return $query->whereIn('user_id', $teamUserIds);
    }

    public function scopeForUserOrTeam($query, int $userId, array $teamUserIds)
    {
        return $query->where(function ($q) use ($userId, $teamUserIds) {
            $q->where('user_id', $userId)
              ->orWhereIn('user_id', $teamUserIds);
        });
    }
}
