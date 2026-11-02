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
                app(ChatRealtime::class)->queueConversation($participant->call->conversation_id, kinds: 'call', callId: $participant->call_id);
            }
        };
        // Admitted people (accepted or connected) may pass call signalling; anyone else may not.
        $admitted = fn (?string $status): bool => in_array($status, ['accepted', 'connected'], true);
        static::created(function (self $participant) use ($wake, $admitted): void {
            $wake($participant);
            if ($admitted($participant->status)) {
                app(ChatRealtime::class)->grantCall($participant->call_id, [(int) $participant->user_id]);
            }
        });
        static::updated(function (self $participant) use ($wake, $admitted): void {
            if ($participant->wasChanged('status') && $participant->status === 'expired' && $participant->call) {
                app(ChatCallNoticeDelivery::class)->enqueue($participant->call);
            }
            if ($participant->wasChanged('status') && $admitted($participant->status) !== $admitted($participant->getOriginal('status'))) {
                $admitted($participant->status)
                    ? app(ChatRealtime::class)->grantCall($participant->call_id, [(int) $participant->user_id])
                    : app(ChatRealtime::class)->revokeCall($participant->call_id, [(int) $participant->user_id]);
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
