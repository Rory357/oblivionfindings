<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ChatMentions
{
    public function __construct(private readonly ChatAccess $access) {}

    /** Only existing, currently authorised members can be explicitly mentioned. */
    public function eligible(OpsConversation $conversation, User $actor): Collection
    {
        $conversation->loadMissing('participants.user');

        return $conversation->participants->where('role', '!=', OpsConversationParticipant::COVER_READER)->pluck('user')->filter(fn ($person) => $person
            && $person->id !== $actor->id && $this->access->allows($person, $conversation))->unique('id')->values();
    }

    /** Offsets are Unicode code points, not browser UTF-16 units or UTF-8 bytes. */
    public function prepare(OpsConversation $conversation, User $actor, string $raw, array $input): array
    {
        if (! $input) {
            return [];
        }
        $eligible = $this->eligible($conversation, $actor)->keyBy('id');
        $leading = mb_strlen($raw, 'UTF-8') - mb_strlen(ltrim($raw), 'UTF-8');
        $content = trim($raw);
        $end = 0;
        $rows = [];
        foreach (collect($input)->sortBy('start')->values() as $item) {
            $person = $eligible->get($item['user_id']);
            $start = (int) $item['start'] - $leading;
            $length = (int) $item['length'];
            $label = $person ? '@'.trim($person->name) : '';
            if (! $person || $start < $end || $length !== mb_strlen($label, 'UTF-8')
                || mb_substr($content, $start, $length, 'UTF-8') !== $label) {
                throw ValidationException::withMessages(['mentions' => 'A mentioned person or their name has changed. Remove that mention or choose an eligible member again. Your draft is kept.']);
            }
            $rows[] = ['user_id' => $person->id, 'start' => $start, 'length' => $length, 'label' => $label];
            $end = $start + $length;
        }

        return $rows;
    }
}
