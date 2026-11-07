<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which Oblivion record a message links to, by type and id only. Access is checked on every view. */
class ChatMessageCard extends Model
{
    protected $fillable = ['message_id', 'card_type', 'record_id', 'position'];

    protected $casts = ['position' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'message_id');
    }
}
