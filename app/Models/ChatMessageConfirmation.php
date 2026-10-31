<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member confirming they have read a message whose sender asked for confirmation. */
class ChatMessageConfirmation extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'user_id', 'confirmed_at'];

    protected $casts = ['confirmed_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
