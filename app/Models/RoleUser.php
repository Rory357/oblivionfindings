<?php

namespace App\Models;

use App\Services\Eligibility\WorkforceEligibilityRefresh;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Existing composite membership identity and behavior, with refresh intent only. */
class RoleUser extends Pivot
{
    protected $table = 'role_user';

    public $timestamps = false;

    protected static function booted(): void
    {
        $changed = fn (self $membership) => app(WorkforceEligibilityRefresh::class)->membershipChanged((int) $membership->user_id);
        static::saved($changed);
        static::deleted($changed);
    }
}
