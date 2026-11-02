<?php

namespace App\Domain\SecurityDevices\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An access policy (which doors, on which schedule) on a UniFi Access console (S&D Phase H). */
final class AccessPolicy extends Model
{
    protected $table = 'security_access_policies';

    protected $fillable = ['console_id', 'external_id', 'name', 'door_ids', 'schedule', 'seen_at'];

    protected $casts = [
        'door_ids' => 'array',
        'schedule' => 'array',
        'seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }
}
