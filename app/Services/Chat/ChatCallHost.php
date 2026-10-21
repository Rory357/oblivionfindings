<?php

namespace App\Services\Chat;

use App\Models\ChatCall;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Support\Collection;

final class ChatCallHost
{
    public function __construct(private readonly ChatAccess $access, private readonly OpsMessageVisibilityService $visibility) {}

    /** Called under the call lock. Prefer current staff, then another authorised participant. */
    public function successor(ChatCall $call, Collection $participants): ?int
    {
        $eligible = $participants->sortBy('id')->filter(fn ($participant) => in_array($participant->status, ['accepted', 'connected'], true)
            && $participant->user && $this->access->allows($participant->user, $call->conversation, true));
        $staff = $eligible->first(fn ($participant) => $this->visibility->isCurrentStaff($participant->user)
            && ! ($call->conversation->conversation_type === 'family'
                && $participant->user->canAccessClientPortal($call->conversation->client)));
        $chosen = $staff ?? $eligible->first();

        return $chosen ? (int) $chosen->user_id : null;
    }
}
