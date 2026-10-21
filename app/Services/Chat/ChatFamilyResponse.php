<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ChatFamilyResponse
{
    /** Caller holds the conversation lock and persists these changes with the message. */
    public function sent(OpsConversation $conversation, OpsMessage $message): void
    {
        if ($conversation->conversation_type !== 'family') {
            return;
        }
        if ($message->sender_type === 'family') {
            if (! $conversation->family_response_started_at) {
                $conversation->family_response_started_at = $message->created_at;
                $conversation->family_response_message_id = $message->id;
            }
        } else {
            $conversation->family_response_started_at = null;
            $conversation->family_response_message_id = null;
        }
    }

    public function project(OpsConversation $conversation, User $actor, array $policy): array
    {
        $empty = ['state' => 'unconfigured', 'due_at' => null, 'timezone' => null, 'working_minutes' => null];
        $schedule = $policy['response_schedule'] ?? null;
        if ($schedule === null) {
            return $empty;
        }
        try {
            $schedule = app(ChatResponseSchedule::class)->validate($schedule);
        } catch (ValidationException) {
            return $empty;
        }
        $base = ['state' => 'not_pending', 'due_at' => null, 'timezone' => $schedule['timezone'], 'working_minutes' => $schedule['response_minutes']];
        if ($conversation->queue_status !== 'open' || ! $conversation->family_response_started_at || ! $conversation->family_response_message_id || $conversation->is_archived) {
            return $base;
        }
        // A current work summary must not expose a request from before this member's history grant.
        if (! app(ChatAccess::class)->messages($conversation, $actor, true)->whereKey($conversation->family_response_message_id)->exists()) {
            return [...$base, 'state' => 'history_restricted'];
        }
        $due = app(ChatResponseSchedule::class)->dueAt(CarbonImmutable::instance($conversation->family_response_started_at), $schedule);
        if (! $due) {
            return $empty;
        }

        return [...$base, 'state' => $due->lessThan(now()) ? 'overdue' : 'due', 'due_at' => $due->toISOString()];
    }
}
