<?php

namespace App\Services\Chat;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A private staff chat keeps a leaver's membership (Stephan, 31 Oct 2026). Each absence is one
 * gap on that membership: a re-hired member gets the chat back, but never the messages sent
 * while they were away, because they weren't staff then. Everyone else still sees everything,
 * and investigator grants are separate. Family conversations follow their own authority, not
 * membership history, so they never get gaps.
 */
final class ChatMembershipGaps
{
    public function __construct(private readonly HrCurrentStaffService $currentStaff) {}

    /**
     * Leaves out the messages a member's gaps cover; their own messages stay. $member is the
     * membership id, or the column holding it when the query joins memberships.
     */
    public function hide($query, int|string $member, int $viewerId)
    {
        return $query->where(fn ($visible) => $visible->where('ops_messages.sender_id', $viewerId)
            ->orWhereNotExists(fn ($gap) => $gap->selectRaw('1')->from('chat_membership_gaps')
                ->when(is_int($member),
                    fn ($rows) => $rows->where('chat_membership_gaps.participant_id', $member),
                    fn ($rows) => $rows->whereColumn('chat_membership_gaps.participant_id', $member))
                ->whereColumn('chat_membership_gaps.left_at', '<=', 'ops_messages.created_at')
                ->where(fn ($open) => $open->whereNull('chat_membership_gaps.returned_at')
                    ->orWhereColumn('chat_membership_gaps.returned_at', '>', 'ops_messages.created_at'))));
    }

    /**
     * Opens a gap on each private staff chat membership of someone who is no longer current
     * staff, and closes it once they're current again. Runs hourly (chat:sync-membership-gaps),
     * and for one person when Chat offboarding completes. Returns how many gaps changed.
     *
     * @param  array<int, int>|null  $userIds
     */
    public function sync(?array $userIds = null): int
    {
        $memberships = DB::table('ops_conversation_participants')
            ->join('ops_conversations', 'ops_conversations.id', '=', 'ops_conversation_participants.conversation_id')
            ->whereIn('ops_conversations.conversation_type', ['direct', 'group'])
            ->whereNull('ops_conversations.client_id')
            ->when($userIds !== null, fn ($rows) => $rows->whereIn('ops_conversation_participants.user_id', $userIds))
            ->get(['ops_conversation_participants.id', 'ops_conversation_participants.user_id']);
        if ($memberships->isEmpty()) {
            return 0;
        }
        $current = $this->currentStaff->currentUsersQuery()
            ->whereIn('users.id', $memberships->pluck('user_id')->unique()->values())
            ->pluck('users.id')->map(fn ($id) => (int) $id)->flip();
        $open = DB::table('chat_membership_gaps')->whereIn('participant_id', $memberships->pluck('id'))
            ->whereNull('returned_at')->get()->keyBy('participant_id');

        $changed = 0;
        foreach ($memberships->groupBy('user_id') as $userId => $rows) {
            [$away, $present] = $rows->partition(fn ($row) => $open->has($row->id));
            if (! $current->has((int) $userId) && $present->isNotEmpty()) {
                $changed += $this->open((int) $userId, $present->pluck('id'));
            }
            if ($current->has((int) $userId) && $away->isNotEmpty()) {
                $changed += $this->close((int) $userId, $away->map(fn ($row) => $open->get($row->id)));
            }
        }

        return $changed;
    }

    /** @param Collection<int, int> $participantIds */
    private function open(int $userId, Collection $participantIds): int
    {
        $leftAt = $this->leftAt($userId)->toDateTimeString();
        // Gaps never overlap: a later absence starts after the last return.
        $lastReturn = DB::table('chat_membership_gaps')->whereIn('participant_id', $participantIds)
            ->groupBy('participant_id')->selectRaw('participant_id, max(returned_at) as returned_at')->pluck('returned_at', 'participant_id');

        return DB::table('chat_membership_gaps')->insert($participantIds->map(fn ($id) => [
            'participant_id' => $id, 'left_at' => max($leftAt, (string) ($lastReturn[$id] ?? '')),
            'created_at' => now(), 'updated_at' => now(),
        ])->values()->all()) ? $participantIds->count() : 0;
    }

    /** @param Collection<int, object> $gaps */
    private function close(int $userId, Collection $gaps): int
    {
        $changed = 0;
        foreach ($gaps as $gap) {
            $changed += DB::table('chat_membership_gaps')->where('id', $gap->id)->whereNull('returned_at')
                ->update(['returned_at' => $this->returnedAt($userId, $gap->left_at)->toDateTimeString(), 'updated_at' => now()]);
        }

        return $changed;
    }

    /** The day after the HR end date (their last working day); without one, the start of the day this first sees it. */
    private function leftAt(int $userId): CarbonImmutable
    {
        $zone = $this->zone();
        $today = CarbonImmutable::now($zone)->startOfDay();
        $ended = HrEmployeeProfile::withTrashed()->where('user_id', $userId)->orderByDesc('id')->first(['end_date'])?->end_date;
        $left = $ended ? CarbonImmutable::parse($ended->toDateString(), $zone)->addDay() : $today;

        return ($left->greaterThan($today) ? $today : $left)->utc();
    }

    /** The start date of the new employment; otherwise now, when this first sees them current again. */
    private function returnedAt(int $userId, string $leftAt): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $started = HrEmployeeProfile::where('user_id', $userId)->orderByDesc('id')->first(['start_date'])?->start_date;
        $back = $started ? CarbonImmutable::parse($started->toDateString(), $this->zone())->utc() : null;

        return $back && $back->greaterThan(CarbonImmutable::parse($leftAt, 'UTC')) && $back->lessThanOrEqualTo($now) ? $back : $now;
    }

    private function zone(): string
    {
        return (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC');
    }
}
