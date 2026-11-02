<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message waiting to be sent. It is not a message until it goes; it then becomes a normal
 * message whose scheduled_message_id points back here.
 */
class ChatScheduledMessage extends Model
{
    use HasUuids;

    protected $fillable = ['conversation_id', 'sender_id', 'mutation_id', 'encrypted_payload', 'send_at', 'at_next_shift', 'state', 'message_id', 'error_code'];

    // Encrypted like a private draft, and never serialised.
    protected $casts = ['encrypted_payload' => 'encrypted:array', 'send_at' => 'datetime', 'at_next_shift' => 'boolean'];

    protected $hidden = ['encrypted_payload'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(OpsConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'message_id');
    }
}
