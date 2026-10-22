<?php

namespace App\Models;

use App\Services\Chat\ChatCallNoticeDelivery;
use App\Services\Chat\ChatRealtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatCall extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['ring_until' => 'datetime', 'connected_at' => 'datetime', 'ended_at' => 'datetime', 'answered_by' => 'integer'];

    protected static function booted(): void
    {
        static::created(fn (self $call) => app(ChatRealtime::class)->queueConversation($call->conversation_id, kinds: 'call', callId: $call->id));
        static::updated(function (self $call): void {
            if ($call->wasChanged('ended_at') && $call->ended_at) {
                app(ChatCallNoticeDelivery::class)->enqueue($call);
                // Nobody may signal on a call that has ended.
                app(ChatRealtime::class)->revokeCall($call->id, $call->participants()->pluck('user_id')->map(fn ($id) => (int) $id)->all());
            }
            if ($call->wasChanged(['status', 'host_id', 'connected_at', 'ended_at'])) {
                app(ChatRealtime::class)->queueConversation($call->conversation_id, kinds: 'call', callId: $call->id);
            }
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(OpsConversation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatCallParticipant::class, 'call_id');
    }
}
