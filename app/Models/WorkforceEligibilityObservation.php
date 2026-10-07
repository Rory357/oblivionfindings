<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Last observation for display. Canonical assignment checks do not consume this cache. */
class WorkforceEligibilityObservation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'failed_rules' => 'array', 'observed_versions' => 'array', 'condition_version' => 'integer',
        'block_count' => 'integer', 'warning_count' => 'integer', 'user_id' => 'integer',
        'checked_at' => 'datetime', 'last_successful_at' => 'datetime', 'failed_at' => 'datetime',
    ];
}
