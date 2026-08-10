<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reviewer's acknowledgement of an application break-glass misuse signal.
 * `dismissed_through` is the cutoff: the signal re-surfaces once relevant
 * activity is newer than this timestamp.
 */
class BreakGlassFlagDismissal extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
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
