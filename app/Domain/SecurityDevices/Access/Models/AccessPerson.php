<?php

namespace App\Domain\SecurityDevices\Access\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A person on a UniFi Access console, matched to staff by employee number once a manager confirms (S&D Phase H). */
final class AccessPerson extends Model
{
    protected $table = 'security_access_people';

    protected $fillable = ['console_id', 'external_id', 'first_name', 'last_name', 'employee_number', 'status', 'kind', 'user_id', 'client_id', 'match_state', 'policy_ids', 'seen_at'];

    protected $casts = [
        'policy_ids' => 'array',
        'seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }

    /** @return HasMany<AccessCard, $this> */
    public function cards(): HasMany
    {
        return $this->hasMany(AccessCard::class, 'person_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
