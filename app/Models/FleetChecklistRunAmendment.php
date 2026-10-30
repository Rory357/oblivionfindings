<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attributable correction note kept beside a submitted check. The check's
 * original answers, files and outcome are never changed by it.
 */
class FleetChecklistRunAmendment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'run_id',
        'asset_id',
        'note',
        'recorded_by_user_id',
        'recorded_at',
        'request_key',
        'request_fingerprint',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FleetChecklistRun::class, 'run_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
