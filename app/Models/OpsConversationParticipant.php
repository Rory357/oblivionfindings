<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpsConversationParticipant extends Model
{
    use HasFactory;

    /**
     * Authorised care-team cover who opened or muted a family thread. The row only
     * holds their personal read and mute state; it is not membership, so it never
     * changes the disclosed audience, recipients, mentions or access.
     */
    public const COVER_READER = 'cover_reader';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'last_read_at',
        'is_muted',
        'is_favourite',
        'last_read_message_id',
        'is_archived_for_user',
        'unread_marker',
        'history_after_id',
        'history_joined_at',
        'callback_history_after_id',
        'family_work_history_after_id',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'is_muted' => 'boolean',
        'is_favourite' => 'boolean',
        'is_archived_for_user' => 'boolean',
        'history_after_id' => 'integer',
        'history_joined_at' => 'datetime',
        'callback_history_after_id' => 'integer',
        'family_work_history_after_id' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(OpsConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
