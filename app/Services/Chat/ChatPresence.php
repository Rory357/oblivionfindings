<?php

namespace App\Services\Chat;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Jobs\PublishChatPresence;
use App\Models\OpsConversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Who is connected, from Oblivion Live's reports (spec sections 5 and 6). Nothing about presence
 * is stored except chat_preferences.last_seen_at; the online flag lives in the cache and expires
 * unless a ticket renewal refreshes it, so a lost "offline" report corrects itself.
 */
class ChatPresence
{
    private const ONLINE_SECONDS = 20 * 60;

    private const PUBLISH_SECONDS = 60;

    private const MAX_RECIPIENTS = 2000;

    public function report(int $userId, string $state): void
    {
        DB::table('chat_preferences')->insertOrIgnore(['user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('chat_preferences')->where('user_id', $userId)->update(['last_seen_at' => now(), 'updated_at' => now()]);
        $state === 'online' ? $this->touch($userId) : Cache::forget($this->key($userId));
        $this->queuePublish($userId);
    }

    /** Ticket renewals keep a long connection counted as online. */
    public function touch(int $userId): void
    {
        Cache::put($this->key($userId), 1, self::ONLINE_SECONDS);
    }

    public function online(int $userId): bool
    {
        return (bool) Cache::get($this->key($userId));
    }

    /** on_shift is added when the roster feeds Chat (plan C5.10). */
    public function state(int $userId): string
    {
        if (! $this->online($userId)) {
            return 'offline';
        }
        $availability = DB::table('chat_preferences')->where('user_id', $userId)->value('availability');

        return $availability === 'available' ? 'available' : 'away';
    }

    /** At most once a minute per person; a change inside the minute goes out when it ends. */
    public function queuePublish(int $userId): void
    {
        if (Cache::add('chat:presence:published:'.$userId, 1, self::PUBLISH_SECONDS)) {
            $this->publish($userId);

            return;
        }
        if (Cache::add('chat:presence:pending:'.$userId, 1, self::PUBLISH_SECONDS)) {
            try {
                PublishChatPresence::dispatch($userId)->delay(now()->addSeconds(self::PUBLISH_SECONDS));
            } catch (\Throwable $error) {
                report($error);
            }
        }
    }

    public function publish(int $userId): void
    {
        $ids = $this->audience($userId);
        if ($ids !== []) {
            app(OblivionLive::class)->publish($ids, 'presence', ['user_id' => $userId, 'state' => $this->state($userId)]);
        }
    }

    /**
     * Current staff who share a conversation with the person and may still read it.
     *
     * @return array<int, int>
     */
    public function audience(int $userId): array
    {
        $shared = DB::table('ops_conversation_participants')->where('user_id', $userId)->pluck('conversation_id');
        if ($shared->isEmpty()) {
            return [];
        }
        $candidates = DB::table('ops_conversation_participants')->whereIn('conversation_id', $shared)
            ->where('user_id', '!=', $userId)->select('user_id', 'conversation_id')->limit(self::MAX_RECIPIENTS * 4)->get()
            ->groupBy('user_id');
        $staff = app(HrCurrentStaffService::class)->currentUsersQuery()->whereIn('users.id', $candidates->keys())->get()->keyBy('id');
        $conversations = OpsConversation::whereIn('id', $shared)->with('client')->get()->keyBy('id');
        $access = app(ChatAccess::class);
        $ids = [];
        foreach ($candidates as $id => $rows) {
            $viewer = $staff->get($id);
            if (! $viewer || count($ids) >= self::MAX_RECIPIENTS) {
                continue;
            }
            foreach ($rows as $row) {
                if (($conversation = $conversations->get($row->conversation_id)) && $access->allows($viewer, $conversation)) {
                    $ids[] = (int) $id;
                    break;
                }
            }
        }

        return $ids;
    }

    private function key(int $userId): string
    {
        return 'chat:presence:online:'.$userId;
    }
}
