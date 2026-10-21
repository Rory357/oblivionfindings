<?php

namespace App\Services\Chat;

use App\Models\ChatCall;
use App\Models\ChatCallParticipant;

final class ChatCallOutcome
{
    public static function forParticipant(ChatCall $call, ChatCallParticipant $participant): string
    {
        if ($participant->status === 'answered_elsewhere') {
            return 'answered_elsewhere';
        }
        if ($participant->status === 'expired') {
            return $participant->joined_at ? 'left' : 'missed';
        }
        if (in_array($participant->status, ['removing', 'removed'], true)) {
            return 'removed';
        }
        if ($participant->status === 'declined') {
            return 'declined';
        }
        if ($participant->status === 'revoked') {
            return 'access_ended';
        }
        if (! $call->ended_at) {
            return $participant->left_at ? 'left' : 'ongoing';
        }
        if (! $participant->joined_at && $call->started_by !== $participant->user_id) {
            return 'missed';
        }
        if (! $call->connected_at) {
            if ($call->started_by === $participant->user_id) {
                if ($call->status === 'cancelled') {
                    return 'cancelled';
                }
                if ($call->relationLoaded('participants')) {
                    $others = $call->participants->where('user_id', '!=', $participant->user_id);
                    if ($others->isNotEmpty() && $others->every(fn ($person) => $person->status === 'declined')) {
                        return 'declined_by_others';
                    }
                }
            }

            return $call->started_by === $participant->user_id ? 'unanswered' : 'not_connected';
        }

        return $participant->joined_at ? 'ended' : 'not_connected';
    }
}
