<?php

namespace App\Domain\SecurityDevices\Access\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A visitor pass on a UniFi Access console (S&D Phase H). */
final class AccessVisitor extends Model
{
    protected $table = 'security_access_visitors';

    protected $fillable = ['console_id', 'external_id', 'name', 'status', 'starts_at', 'ends_at', 'seen_at'];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'seen_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<AccessConsole, $this> */
    public function console(): BelongsTo
    {
        return $this->belongsTo(AccessConsole::class, 'console_id');
    }
}
