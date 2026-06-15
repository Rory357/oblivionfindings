<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's acknowledgement of a derived break-glass misuse signal. Keyed by
 * (organisation, signal_type, signal_key); `dismissed_through` is the cutoff —
 * the signal re-surfaces once relevant activity is newer than this timestamp.
 */
class BreakGlassFlagDismissal extends Model
{
    protected $fillable = [
        'organization_id',
        'signal_type',
        'signal_key',
        'dismissed_by',
        'reason',
        'dismissed_through',
    ];

    protected $casts = [
        'dismissed_through' => 'datetime',
    ];

    public function dismissedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
