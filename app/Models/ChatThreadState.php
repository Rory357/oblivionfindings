<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's state for one thread: following, muted and how far they have read. */
class ChatThreadState extends Model
{
    protected $fillable = ['root_message_id', 'user_id', 'following', 'muted', 'last_read_message_id'];

    protected $casts = ['following' => 'boolean', 'muted' => 'boolean', 'last_read_message_id' => 'integer'];

    public function rootMessage(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'root_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'last_read_message_id');
    }
}
