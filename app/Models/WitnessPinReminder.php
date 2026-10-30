<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One reminder to set a witness PIN (eMAR P11 PIN status). */
class WitnessPinReminder extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'reminded_by'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remindedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reminded_by');
    }
}
