<?php

namespace App\Models;

use App\Services\Chat\ChatCallNoticeDelivery;
use App\Services\Chat\ChatRealtime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatCallParticipant extends Model
{
    protected $guarded = [];

    protected $casts = ['joined_at' => 'datetime', 'left_at' => 'datetime', 'invitation_expires_at' => 'datetime', 'invitation_version' => 'integer'];

    public function matchesInvitation(?string $identity): bool
    {
        return $identity === null ? ($this->invitation_version ?? 1) === 1 : hash_equals($this->media_identity, $identity);
    }

    public function invitationExpired(ChatCall $call): bool
    {
        // Existing invitations predate the per-person expiry column.
        return $this->status === 'invited' && ($this->invitation_expires_at ?? $call->ring_until)->lte(now());
    }

    protected static function booted(): void
    {
        $wake = function (self $participant): void {
            if ($participant->call) {
                app(ChatRealtime::class)->queueConversation($participant->call->conversation_id);
            }
        };
        static::created($wake);
        static::updated(function (self $participant) use ($wake): void {
            if ($participant->wasChanged('status') && $participant->status === 'expired' && $participant->call) {
                app(ChatCallNoticeDelivery::class)->enqueue($participant->call);
            }
            // Polling heartbeats touch updated_at and must never produce a wake-up loop.
            if ($participant->wasChanged(['status', 'device_id', 'left_at'])) {
                $wake($participant);
            }
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(ChatCall::class, 'call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
