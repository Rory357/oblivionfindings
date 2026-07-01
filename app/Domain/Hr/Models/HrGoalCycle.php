<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OKR cycle / period — the spine the hero cycle selector and every
 * cycle-scoped stat hang off (e.g. "FY26 Q3"). Cycles can nest (a financial
 * year is the parent of its quarters) via parent_cycle_id.
 */
class HrGoalCycle extends Model
{
    protected $table = 'hr_goal_cycles';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'starts_at',
        'ends_at',
        'status',
        'parent_cycle_id',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
    ];

    public function goals(): HasMany
    {
        return $this->hasMany(HrGoal::class, 'cycle_id');
    }

    public function parentCycle(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_cycle_id');
    }

    public function childCycles(): HasMany
    {
        return $this->hasMany(self::class, 'parent_cycle_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /** Whether the supplied date falls inside this cycle's window. */
    public function contains(\DateTimeInterface $date): bool
    {
        return $date >= $this->starts_at->startOfDay()
            && $date <= $this->ends_at->endOfDay();
    }
}
