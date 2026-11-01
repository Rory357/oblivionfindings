<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends the Chat footprint of someone whose employment or approved care
 * relationship has ended. Only memberships the person can no longer use end.
 * A private staff chat keeps the membership, marked as left, so the leaver's
 * name stays and a re-hire gets the chat back without the messages from while
 * they were away (ChatMembershipGaps; Stephan, 31 Oct 2026); other memberships
 * are removed. Messages, call metadata, care records and investigation evidence
 * are never deleted. Owned groups pass to a remaining eligible member, and
 * unresolved callbacks and family work pass to a chosen authorised lead or
 * back to the shared care-team queue. Current access is never removed here:
 * a person who still works here keeps their conversations.
 */
final class ChatOffboarding
{
    public function __construct(
        private readonly ChatAccess $access,
        private readonly ChatCallbacks $callbacks,
        private readonly OpsMessageVisibilityService $staff,
        private readonly ChatMembershipGaps $gaps,
    ) {}

    public function authorize(?User $actor): void
    {
        abort_unless($actor?->canDo('settings.access.manage'), 403);
    }

    public function search(User $actor, string $query): array
    {
        $this->authorize($actor);
        $query = trim($query);
        abort_if(mb_strlen($query) < 2, 422, 'Enter at least two letters of a name.');

        // Only people with a Chat footprint are relevant here.
        return User::query()->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')
            ->where(fn ($people) => $people
                ->whereExists(fn ($rows) => $rows->selectRaw('1')->from('ops_conversation_participants')->whereColumn('ops_conversation_participants.user_id', 'users.id'))
                ->orWhereExists(fn ($rows) => $rows->selectRaw('1')->from('chat_callbacks')->whereColumn('chat_callbacks.assigned_to', 'users.id'))
                ->orWhereExists(fn ($rows) => $rows->selectRaw('1')->from('ops_conversations')->whereColumn('ops_conversations.assigned_to', 'users.id')))
            ->orderBy('name')->limit(10)->get(['id', 'name'])
            ->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name, 'current_staff' => $this->staff->isCurrentStaff($person)])
            ->values()->all();
    }

    public function preview(User $actor, User $person): array
    {
        $this->authorize($actor);
        $plan = $this->plan($person);
        $owners = $this->eligibleOwners($person, $plan);

        return [
            'person' => ['id' => $person->id, 'name' => $person->name],
            'current_staff' => $plan['current_staff'],
            'ending' => [
                'staff' => $plan['memberships']->filter(fn ($row) => $row->conversation->conversation_type !== 'family')->count(),
                'family' => $plan['memberships']->filter(fn ($row) => $row->conversation->conversation_type === 'family')->count(),
            ],
            'kept' => $plan['kept'],
            'owned_groups' => $plan['memberships']->filter(fn ($row) => $this->ownsGroup($row))->map(fn ($row) => [
                'id' => (int) $row->conversation_id, 'title' => $row->conversation->title ?: 'Private group',
                'next_owner' => $this->nextOwner($row->conversation, $person)?->name,
            ])->values()->all(),
            'callbacks' => $plan['callbacks']->count(),
            'family_work' => $plan['family_work']->count(),
            'held' => $plan['memberships']->filter(fn ($row) => app(ChatPreservation::class)->isHeld((int) $row->conversation_id))->count(),
            'eligible_owners' => $owners->map(fn (User $owner) => ['id' => $owner->id, 'name' => $owner->name])->values()->all(),
            'actionable' => $plan['memberships']->isNotEmpty() || $plan['callbacks']->isNotEmpty() || $plan['family_work']->isNotEmpty(),
        ];
    }

    public function complete(User $actor, User $person, array $data): array
    {
        return DB::transaction(function () use ($actor, $person, $data): array {
            $this->authorize($actor);
            User::whereKey($person->id)->lockForUpdate()->firstOrFail();
            $plan = $this->plan($person);
            abort_if($plan['memberships']->isEmpty() && $plan['callbacks']->isEmpty() && $plan['family_work']->isEmpty(), 422,
                'Nothing to offboard. Current access is kept while employment or the care relationship continues.');
            $owner = null;
            if (($data['owner'] ?? 'care_team') === 'person') {
                $owner = $this->eligibleOwners($person, $plan)->firstWhere('id', (int) ($data['owner_id'] ?? 0));
                abort_unless($owner, 422, 'Choose an authorised lead who can access all of this person’s unresolved family work.');
            }
            $summary = ['memberships_ended' => 0, 'ownership_transferred' => 0, 'callbacks_reassigned' => 0, 'callbacks_returned' => 0,
                'family_work_reassigned' => 0, 'family_work_returned' => 0];
            $affected = collect([$person->id]);

            foreach ($plan['memberships'] as $row) {
                $conversation = OpsConversation::whereKey($row->conversation_id)->lockForUpdate()->firstOrFail();
                if ($this->ownsGroup($row) && ! $conversation->participants()->where('role', 'admin')->where('user_id', '!=', $person->id)->exists()) {
                    if ($next = $this->nextOwner($conversation, $person)) {
                        $conversation->participants()->where('user_id', $next->id)->update(['role' => 'admin']);
                        $summary['ownership_transferred']++;
                    }
                }
                $affected = $affected->merge($conversation->participants()->pluck('user_id'));
                if ($this->keepsMembership($row)) {
                    // A re-hire comes back as a member, never as the group's owner.
                    OpsConversationParticipant::whereKey($row->id)->where('role', 'admin')->update(['role' => 'member']);
                } else {
                    OpsConversationParticipant::whereKey($row->id)->delete();
                    DB::table('chat_invitations')->where('conversation_id', $conversation->id)->where('user_id', $person->id)->where('status', 'accepted')
                        ->update(['status' => 'removed', 'updated_at' => now()]);
                }
                DB::table('chat_invitations')->where('conversation_id', $conversation->id)->where('status', 'pending')
                    ->where(fn ($query) => $query->where('invited_by', $person->id)->orWhere('user_id', $person->id))
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                $conversation->chat_version++;
                $conversation->save();
                foreach (ChatCall::where('conversation_id', $conversation->id)->whereNull('ended_at')->get() as $call) {
                    app(ChatCallReconciler::class)->reconcile($call);
                }
                if ($row->role !== OpsConversationParticipant::COVER_READER) {
                    $summary['memberships_ended']++;
                }
            }

            foreach ($plan['callbacks'] as $callback) {
                $conversation = OpsConversation::findOrFail($callback->conversation_id);
                $next = $owner && $this->callbacks->owners($conversation)->contains('id', $owner->id) ? $owner : null;
                DB::table('chat_callbacks')->where('id', $callback->id)->update([
                    'assigned_to' => $next?->id,
                    'status' => $next ? ($callback->agreed_time ? 'scheduled' : 'assigned') : 'requested',
                    'version' => (int) $callback->version + 1, 'updated_at' => now(),
                ]);
                DB::table('chat_callback_events')->insert(['callback_id' => $callback->id, 'actor_id' => $actor->id, 'action' => 'offboarded', 'created_at' => now()]);
                $summary[$next ? 'callbacks_reassigned' : 'callbacks_returned']++;
                app(ChatRealtime::class)->queueConversation($conversation->id);
            }

            foreach ($plan['family_work'] as $conversation) {
                $conversation = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $next = $owner && $this->canOwnFamilyWork($owner, $conversation) ? $owner : null;
                $conversation->update(['assigned_to' => $next?->id, 'chat_version' => $conversation->chat_version + 1]);
                $mutation = (string) Str::uuid();
                DB::table('chat_family_work_events')->insert([
                    'conversation_id' => $conversation->id, 'actor_id' => $actor->id, 'mutation_id' => $mutation,
                    'request_hash' => hash('sha256', json_encode(['offboarding', $conversation->id, $person->id, $next?->id, $mutation], JSON_THROW_ON_ERROR)),
                    'action' => 'handoff', 'owner_id' => $next?->id, 'queue_status' => $conversation->queue_status, 'encrypted_note' => null, 'created_at' => now(),
                ]);
                $summary[$next ? 'family_work_reassigned' : 'family_work_returned']++;
                app(ChatRealtime::class)->queueConversation($conversation->id);
            }

            // Marks the kept memberships as left, unless the hourly check already has.
            $this->gaps->sync([$person->id]);
            foreach ($affected->unique() as $id) {
                Cache::forget("user:{$id}:ops-unread:v1");
            }
            AuditLog::create(['user_id' => $actor->id, 'action' => 'chat.offboarding.completed', 'meta' => [
                'person_id' => $person->id, 'owner' => $owner ? 'person' : 'care_team', 'owner_id' => $owner?->id, ...$summary,
            ]]);

            return $summary;
        });
    }

    /** @return array{current_staff: bool, memberships: Collection, callbacks: Collection, family_work: Collection, kept: int} */
    private function plan(User $person): array
    {
        $current = $this->staff->isCurrentStaff($person);
        $rows = OpsConversationParticipant::where('user_id', $person->id)->with('conversation.client')->get()->filter(fn ($row) => $row->conversation);
        $expired = $rows->filter(fn ($row) => ! $this->stillAuthorised($person, $row->conversation, $current));
        // A kept membership already marked as left needs nothing more once it owns no group.
        $left = DB::table('chat_membership_gaps')->whereIn('participant_id', $expired->pluck('id'))->whereNull('returned_at')->pluck('participant_id')->flip();
        $expired = $expired->reject(fn ($row) => $this->keepsMembership($row) && $left->has($row->id) && ! $this->ownsGroup($row))->values();

        return [
            'current_staff' => $current,
            'memberships' => $expired,
            'kept' => $rows->count() - $expired->count(),
            // Someone who still works here keeps their own work; only former staff's work moves.
            'callbacks' => $current ? collect() : DB::table('chat_callbacks')->where('assigned_to', $person->id)->whereIn('status', ChatCallbacks::ACTIVE)->get(),
            'family_work' => $current ? collect() : OpsConversation::where('conversation_type', 'family')->where('assigned_to', $person->id)->get(),
        ];
    }

    private function stillAuthorised(User $person, OpsConversation $conversation, bool $currentStaff): bool
    {
        if ($conversation->conversation_type === 'family' && $conversation->client && $person->hasRole('client', 'next_of_kin')) {
            return $person->canAccessClientPortal($conversation->client);
        }

        return $currentStaff;
    }

    /** Private staff chats keep a leaver's membership; family and client conversations don't. */
    private function keepsMembership(OpsConversationParticipant $row): bool
    {
        return in_array($row->conversation->conversation_type, ['direct', 'group'], true) && ! $row->conversation->client_id;
    }

    private function ownsGroup(OpsConversationParticipant $row): bool
    {
        return $row->role === 'admin' && $row->conversation->conversation_type === 'group' && ! $row->conversation->client_id;
    }

    /**
     * The longest-standing remaining member who is current staff with access to
     * the channel's site. Full allows() is not used here: while the row of a
     * leaver who is now a client or family sign-in still exists, the staff
     * conversation check fails for everyone.
     */
    private function nextOwner(OpsConversation $conversation, User $leaving): ?User
    {
        return $conversation->members()->where('user_id', '!=', $leaving->id)->orderBy('id')->with('user')->get()
            ->pluck('user')->filter()->first(fn (User $member) => $this->staff->isCurrentStaff($member)
                && $this->access->channelSiteAllows($member, $conversation)
                && (! $conversation->is_managed_channel || $member->canDo('settings.access.manage')));
    }

    private function canOwnFamilyWork(User $owner, OpsConversation $conversation): bool
    {
        return $this->staff->isCurrentStaff($owner) && ! $owner->canAccessClientPortal($conversation->client)
            && $this->access->allows($owner, $conversation, true);
    }

    /** Leads who can take every unresolved callback and family conversation this person owned. */
    private function eligibleOwners(User $person, array $plan): Collection
    {
        $conversations = OpsConversation::whereIn('id', $plan['callbacks']->pluck('conversation_id')->merge($plan['family_work']->pluck('id'))->unique())->with('client')->get();
        if ($conversations->isEmpty()) {
            return collect();
        }
        $candidates = $this->access->staffCandidates($conversations->first(), true)->where('users.id', '!=', $person->id)->limit(200)->get();

        return $candidates->filter(fn (User $candidate) => $conversations->every(fn (OpsConversation $conversation) => $this->canOwnFamilyWork($candidate, $conversation)))
            ->sortBy('name')->take(50)->values();
    }
}
