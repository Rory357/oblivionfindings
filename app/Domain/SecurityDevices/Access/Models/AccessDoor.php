<?php

namespace App\Domain\SecurityDevices\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A door on a UniFi Access console (S&D Phase H). */
final class AccessDoor extends Model
{
    protected $table = 'security_access_doors';

    protected $fillable = ['console_id', 'external_id', 'name', 'lock_state', 'door_state', 'hub_bound', 'readers', 'device_id', 'seen_at'];

    protected $casts = [
        'hub_bound' => 'boolean',
        'readers' => 'array',
        'seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }
}
