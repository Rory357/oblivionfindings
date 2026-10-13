<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Durable re-evaluation intent, never an assignment or permission decision. */
class WorkforceEligibilityRecheck extends Model
{
    protected $guarded = [];

    protected $casts = [
        'user_ids' => 'array', 'site_ids' => 'array', 'client_ids' => 'array', 'shift_ids' => 'array',
        'all_assigned' => 'boolean', 'source_version' => 'integer', 'attempts' => 'integer',
        'last_shift_id' => 'integer', 'scanned_count' => 'integer', 'failed_count' => 'integer',
        'available_at' => 'datetime', 'last_attempt_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'processing', 'failed']);
    }

    public function scopeAffecting(Builder $query, Shift $shift): Builder
    {
        return $query->where(function (Builder $scope) use ($shift): void {
            $scope->where('all_assigned', true)->orWhereJsonContains('shift_ids', (int) $shift->id);
            if ($shift->user_id) {
                $scope->orWhereJsonContains('user_ids', (int) $shift->user_id);
            }
            if ($siteId = $shift->site_id ?: $shift->client?->site_id) {
                $scope->orWhereJsonContains('site_ids', (int) $siteId);
            }
            if ($shift->client_id) {
                $scope->orWhereJsonContains('client_ids', (int) $shift->client_id);
            }
        });
    }
}
