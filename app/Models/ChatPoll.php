<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The poll a poll message holds; one per message. */
class ChatPoll extends Model
{
    protected $fillable = ['message_id', 'question', 'allows_multiple', 'anonymous', 'closes_at', 'closed_at', 'closed_by'];

    protected $casts = ['allows_multiple' => 'boolean', 'anonymous' => 'boolean', 'closes_at' => 'datetime', 'closed_at' => 'datetime'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(OpsMessage::class, 'message_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(ChatPollOption::class, 'poll_id')->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(ChatPollVote::class, 'poll_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
