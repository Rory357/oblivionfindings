<?php

namespace App\Domain\SecurityDevices\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A card on a person: only a hash of its token and the number printed on it (S&D Phase H). */
final class AccessCard extends Model
{
    protected $table = 'security_access_cards';

    protected $fillable = ['person_id', 'token_hash', 'display_id', 'status', 'seen_at'];

    protected $casts = [
        'seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessPerson, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(AccessPerson::class, 'person_id');
    }
}
