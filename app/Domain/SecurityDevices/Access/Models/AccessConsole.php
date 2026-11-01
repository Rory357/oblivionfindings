<?php

namespace App\Domain\SecurityDevices\Access\Models;

use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Site's UniFi Access console, kept in step through the Site's hub (S&D Phase H). */
final class AccessConsole extends Model
{
    protected $table = 'security_access_consoles';

    protected $fillable = ['site_id', 'address', 'port', 'state', 'last_error', 'last_synced_at', 'events_until', 'exit_buttons_confirmed_at', 'exit_buttons_confirmed_by_user_id', 'connected_by_user_id'];

    protected $casts = [
        'port' => 'integer',
        'last_synced_at' => 'immutable_datetime',
        'events_until' => 'immutable_datetime',
        'exit_buttons_confirmed_at' => 'immutable_datetime',
    ];

    /** @return HasMany<AccessDoor, $this> */
    public function doors(): HasMany
    {
        return $this->hasMany(AccessDoor::class, 'console_id');
    }

    /** @return HasMany<AccessPerson, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(AccessPerson::class, 'console_id');
    }

    /** @return HasMany<AccessEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AccessEvent::class, 'console_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }
}
