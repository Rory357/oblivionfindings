<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatInvitations
{
    public const EXPIRY_DAYS = 7;

    public function __construct(private readonly ChatAccess $access, private readonly ClientFamilyCommunicationAccess $family, private readonly OpsMessageVisibilityService $staff) {}

    public function status(object $invitation): string
    {
        if ($invitation->status === 'pending' && Carbon::parse($invitation->created_at)->addDays(self::EXPIRY_DAYS)->lte(now())) {
            return 'expired';
        }

        return $invitation->status === 'pending' && $this->awaitingFamily($invitation) ? 'awaiting_family' : $invitation->status;
    }

    public function awaitingFamily(object $invitation): bool
    {
        return (bool) ($invitation->consent_required ?? false) && ($invitation->consented_by ?? null) === null;
    }

    /** Relatives currently in the conversation who may agree to (or refuse) another relative joining. */
    public function familyMembers(OpsConversation $conversation)
    {
        $client = $conversation->client;
        if ($conversation->conversation_type !== 'family' || ! $client) {
            return collect();
        }

        return $conversation->members()->where('role', 'family')->with('user')->get()->pluck('user')
            ->filter(fn ($person) => $person && $person->canAccessClientPortal($client) && $this->family->canAppearAsParticipant($person, $client))->values();
    }

    public function pending(int $conversationId)
    {
        return DB::table('chat_invitations')->where('conversation_id', $conversationId)->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(self::EXPIRY_DAYS));
    }

    public function eligible(User $actor, User $person, OpsConversation $conversation): bool
    {
        return $conversation->conversation_type === 'family'
            ? (bool) $conversation->client && $person->canAccessClientPortal($conversation->client) && $this->family->canAppearAsParticipant($person, $conversation->client)
            // Only someone the adder can see, and who sees (and is seen by) the adder and every member the
            // adder can still see. Members who have since moved out of the adder's Sites don't block an
            // addition (Stephan, 23 Oct 2026); a brand-new group needs everyone to see everyone.
            : $this->staff->visibleCurrentStaffQuery($actor)->where('users.id', $person->id)->exists()
                && $this->access->staffAudienceAllows([...$this->membersSeenBy($actor, $conversation), $actor->id, $person->id], $conversation);
    }

    /** Current members the adder can still see; members who moved out of the adder's Sites are left out. */
    private function membersSeenBy(User $actor, OpsConversation $conversation): array
    {
        $members = $conversation->members()->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return $members === [] ? [] : $this->staff->visibleCurrentStaffQuery($actor)->whereIn('users.id', $members)
            ->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }

    public function invite(User $actor, OpsConversation $conversation, array $data): array
    {
        $ids = collect($data['user_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $messages = collect($data['message_ids'] ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $hash = hash('sha256', json_encode([$conversation->id, $ids, $data['history_policy'], $messages]));

        return DB::transaction(function () use ($actor, $conversation, $data, $ids, $messages, $hash) {
            $people = User::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $conversation = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $conversation, true);
            abort_unless($this->access->canManageParticipants($actor, $conversation), 403);
            $existing = DB::table('chat_invitations')->where('invited_by', $actor->id)->where('mutation_id', $data['mutation_id'])->get();
            if ($existing->isNotEmpty()) {
                abort_unless($existing->count() === count($ids) && $existing->every(fn ($row) => $row->request_hash === $hash && (int) $row->conversation_id === $conversation->id), 409, 'This request reference belongs to a different invitation.');
                abort_unless($existing->every(fn ($row) => in_array($this->status($row), ['pending', 'accepted'], true)), 409, 'This invitation has ended. Review a new invitation.');

                return ['pending' => $existing->contains('status', 'pending')];
            }
            abort_unless($conversation->chat_version === $data['version'], 409, 'The conversation changed. Reopen the invitation to review its current audience.');
            abort_unless($people->count() === count($ids) && $people->every(fn ($person) => $this->eligible($actor, $person, $conversation)), 403);
            // The adder, everyone being added and every member the adder can still see must all see each
            // other; members who have since moved out of the adder's Sites don't block it (Stephan, 23 Oct 2026).
            abort_unless($conversation->conversation_type === 'family'
                || $this->access->staffAudienceAllows([...$this->membersSeenBy($actor, $conversation), $actor->id, ...$ids], $conversation),
                403, 'These people cannot all access the same conversation. Review your selection.');
            abort_if($conversation->participants()->whereIn('user_id', $ids)->exists(), 422, 'A selected person is already a member. Review your selection.');
            abort_if($conversation->conversation_type === 'group' && ! $conversation->is_managed_channel
                && $conversation->participants()->count() + count($ids) > ChatAccess::PRIVATE_GROUP_MEMBERS,
                422, 'Private groups can have up to 20 people, including the owner.');
            abort_if($this->pending($conversation->id)->whereIn('user_id', $ids)->exists(), 409, 'A selected person already has a pending invitation.');
            abort_unless($data['history_policy'] === 'selected' ? count($messages) > 0 : count($messages) === 0, 422, 'Review which earlier messages you want to share.');
            abort_unless($this->access->messages($conversation, $actor)->whereIn('id', $messages)->count() === count($messages), 422, 'An earlier message is no longer available to share.');
            $pending = $conversation->conversation_type === 'family';
            // Relatives already in the conversation decide who else may read it. An inviting
            // relative's own invitation is their agreement; a staff invitation waits for one.
            $relatives = $pending ? $this->familyMembers($conversation) : collect();
            $consentRequired = $relatives->isNotEmpty();
            $consentedBy = $consentRequired && $relatives->contains('id', $actor->id) ? $actor->id : null;
            foreach ($people as $person) {
                if ($pending) {
                    abort_unless(DB::table('client_portal_users')->where('client_id', $conversation->client_id)->where('user_id', $person->id)->lockForUpdate()->exists(), 403);
                }
                DB::table('chat_invitations')->insert([
                    'id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'invited_by' => $actor->id, 'user_id' => $person->id,
                    'mutation_id' => $data['mutation_id'], 'request_hash' => $hash, 'status' => $pending ? 'pending' : 'accepted',
                    'history_policy' => $data['history_policy'], 'message_ids' => json_encode($messages),
                    'consent_required' => $consentRequired, 'consented_by' => $consentedBy, 'consented_at' => $consentedBy ? now() : null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! $pending) {
                    $this->join($person, $conversation, $messages);
                }
                AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => $pending ? 'chat.invitation.created' : 'chat.participant.added',
                    'meta' => ['conversation_id' => $conversation->id, 'participant_id' => $person->id, 'history_policy' => $data['history_policy'], 'shared_message_ids' => $messages]]);
            }
            $conversation->increment('chat_version');

            return ['pending' => $pending];
        });
    }

    public function respond(User $actor, string $id, string $action, ?int $version): void
    {
        $reference = DB::table('chat_invitations')->where('id', $id)->first();
        abort_unless($reference, 404);
        DB::transaction(function () use ($actor, $reference, $action, $version) {
            User::whereKey($reference->user_id)->lockForUpdate()->firstOrFail();
            $conversation = OpsConversation::whereKey($reference->conversation_id)->lockForUpdate()->firstOrFail();
            $invitation = DB::table('chat_invitations')->where('id', $reference->id)->lockForUpdate()->first();
            $cancel = $action === 'cancel';
            if (in_array($action, ['consent', 'refuse'], true)) {
                $this->decideConsent($actor, $conversation, $invitation, $action === 'consent');

                return;
            }
            abort_unless($cancel ? $this->access->canManageParticipants($actor, $conversation) : (int) $invitation->user_id === $actor->id, 403);
            abort_if(! $cancel && $this->awaitingFamily($invitation), 404);
            $status = ['accept' => 'accepted', 'decline' => 'declined', 'cancel' => 'cancelled'][$action];
            if ($invitation->status === $status) {
                return;
            }
            abort_unless($invitation->status === 'pending', 409, 'This invitation has already been resolved.');
            abort_if($this->status($invitation) === 'expired', 410, 'This invitation has expired. Ask for a new invitation.');
            if ($action === 'accept') {
                $inviter = User::find($invitation->invited_by);
                abort_unless($inviter && $this->access->canManageParticipants($inviter, $conversation) && $this->eligible($inviter, $actor, $conversation), 403, 'This invitation is no longer available.');
                abort_unless($conversation->chat_version === $version, 409, 'The conversation changed. Review its current members before joining.');
                abort_unless(DB::table('client_portal_users')->where('client_id', $conversation->client_id)->where('user_id', $actor->id)->lockForUpdate()->exists(), 403);
                abort_if($conversation->participants()->where('user_id', $actor->id)->exists(), 409, 'You are already a member.');
                $messages = json_decode($invitation->message_ids, true);
                abort_unless($this->access->messages($conversation, $inviter)->whereIn('id', $messages)->count() === count($messages), 409, 'The reviewed history changed. Ask for a new invitation.');
                $this->join($actor, $conversation, $messages);
            }
            DB::table('chat_invitations')->where('id', $invitation->id)->update(['status' => $status, 'updated_at' => now()]);
            $conversation->increment('chat_version');
            AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.invitation.'.$status, 'meta' => ['conversation_id' => $conversation->id, 'invitation_id' => $invitation->id]]);
        });
    }

    private function decideConsent(User $actor, OpsConversation $conversation, object $invitation, bool $agree): void
    {
        abort_unless((int) $invitation->user_id !== $actor->id && $this->familyMembers($conversation)->contains('id', $actor->id), 403);
        // Repeating the same decision is a no-op, so a retried request is safe.
        if ($agree ? ($invitation->status === 'pending' && ! $this->awaitingFamily($invitation)) : $invitation->status === 'refused') {
            return;
        }
        abort_unless($invitation->status === 'pending' && $this->awaitingFamily($invitation), 409, 'This invitation has already been resolved.');
        abort_if($this->status($invitation) === 'expired', 410, 'This invitation has expired.');
        DB::table('chat_invitations')->where('id', $invitation->id)->update($agree
            ? ['consented_by' => $actor->id, 'consented_at' => now(), 'updated_at' => now()]
            : ['status' => 'refused', 'updated_at' => now()]);
        $conversation->increment('chat_version');
        AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.invitation.'.($agree ? 'family_agreed' : 'family_refused'),
            'meta' => ['conversation_id' => $conversation->id, 'invitation_id' => $invitation->id]]);
    }

    private function join(User $person, OpsConversation $conversation, array $messages): void
    {
        $boundary = (int) (OpsMessage::withTrashed()->where('conversation_id', $conversation->id)->max('id') ?? 0);
        $member = $conversation->participants()->create(['user_id' => $person->id, 'role' => $conversation->conversation_type === 'family' ? 'family' : 'member',
            'history_after_id' => $boundary, 'history_joined_at' => now(),
            'family_work_history_after_id' => (int) (DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)->max('id') ?? 0),
            'callback_history_after_id' => (int) (DB::table('chat_callbacks')->where('conversation_id', $conversation->id)->max('id') ?? 0)]);
        DB::table('chat_history_call_exclusions')->insertUsing(['participant_id', 'call_id'],
            DB::table('chat_calls')->join('chat_call_participants', 'chat_call_participants.call_id', '=', 'chat_calls.id')
                ->where('chat_calls.conversation_id', $conversation->id)->where('chat_call_participants.user_id', $person->id)
                ->selectRaw('? as participant_id, chat_calls.id', [$member->id]));
        foreach ($messages as $id) {
            DB::table('chat_history_grants')->insert(['participant_id' => $member->id, 'message_id' => $id, 'created_at' => now()]);
        }
        Cache::forget("user:{$person->id}:ops-unread:v1");
    }
}
