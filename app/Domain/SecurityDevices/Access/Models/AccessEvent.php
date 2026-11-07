<?php

namespace App\Domain\SecurityDevices\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A door event from a UniFi Access console. The name on it is cleared after 90 days (D14) (S&D Phase H). */
final class AccessEvent extends Model
{
    protected $table = 'security_access_events';

    protected $fillable = ['console_id', 'event_key', 'door_id', 'person_id', 'kind', 'result', 'method', 'actor_name', 'occurred_at'];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }

    /** @return BelongsTo<AccessDoor, $this> */
    public function door(): BelongsTo
    {
        return $this->belongsTo(AccessDoor::class, 'door_id');
    }

    /** @return BelongsTo<AccessPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(AccessPerson::class, 'person_id');
    }
}
