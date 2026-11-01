<?php

namespace App\Http\Controllers\Chat;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatAvailability;
use App\Services\Chat\ChatCallAudience;
use App\Services\Chat\ChatCallHost;
use App\Services\Chat\ChatCallOutcome;
use App\Services\Chat\ChatCallReconciler;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\SelfHostedCalling;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ChatCallController extends Controller
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatSettings $settings, private readonly SelfHostedCalling $media) {}

    public function status(Request $request)
    {
        $data = $request->validate(['device_id' => ['nullable', 'uuid']]);
        $user = $request->user();
        $preference = DB::table('chat_preferences')->where('user_id', $user->id)->first();
        $calls = ChatCall::whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->whereNull('ended_at')->with(['conversation', 'participants.user'])->orderByDesc('created_at')->get();
        $visible = [];
        foreach ($calls as $call) {
            if (! $this->access->allows($user, $call->conversation)) {
                if ($this->media->configured()) {
                    app(ChatCallReconciler::class)->reconcile($call);
                }

                continue;
            }
            if (($call->status === 'ringing' && $call->ring_until->lte(now()))
                || $call->participants->contains(fn ($person) => $person->invitationExpired($call))) {
                if ($this->media->configured()) {
                    app(ChatCallReconciler::class)->reconcile($call);
                    $call->refresh()->load(['conversation', 'participants.user']);
                }
                if ($call->ended_at || ($call->status === 'ringing' && $call->ring_until->lte(now()))) {
                    continue;
                }
            }
            $own = $call->participants->firstWhere('user_id', $user->id);
            if ($own && ($data['device_id'] ?? null) === $own->device_id && in_array($own->status, ['accepted', 'connected'], true)) {
                $own->touch();
            }
            if (in_array($own?->status, ['invited', 'accepted', 'connected'], true) && ! $own->invitationExpired($call)) {
                $visible[] = $this->payload($call, $user);
            }
        }

        return response()->json(['ready' => $this->media->ready(),
            'can_set_off_duty' => app(HrCurrentStaffService::class)->isCurrent($user),
            'unread_count' => app(OpsMessageVisibilityService::class)->cachedUnreadCount($user),
            'availability' => $preference?->availability ?? 'unavailable', 'sound_enabled' => (bool) ($preference?->sound_enabled ?? true),
            'calls' => $visible])->header('Cache-Control', 'private, no-store');
    }

    public function preference(Request $request)
    {
        $data = $request->validate(['availability' => ['sometimes', Rule::in(ChatAvailability::VALUES)], 'sound_enabled' => ['sometimes', 'boolean']]);
        abort_if(($data['availability'] ?? null) === 'off_duty' && ! app(HrCurrentStaffService::class)->isCurrent($request->user()), 403, 'Off duty is only available to current staff.');
        $this->ensurePreferenceRow($request->user());
        // Call starts lock this row before checking availability (lockPeople()).
        // A confirmed off-duty save must not race a later invitation.
        DB::table('chat_preferences')->where('user_id', $request->user()->id)->update([...$data, 'last_seen_at' => now(), 'updated_at' => now()]);

        return response()->noContent();
    }

    public function outcome(Request $request, ChatCall $call)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'invitation_id' => ['nullable', 'uuid']]);
        $user = $request->user();
        $conversation = $call->conversation;
        $this->access->authorize($user, $conversation);
        $person = $call->participants()->where('user_id', $user->id)->firstOrFail();
        $memberId = $conversation->participants()->where('user_id', $user->id)->value('id');
        abort_if($memberId && DB::table('chat_history_call_exclusions')->where('participant_id', $memberId)->where('call_id', $call->id)->exists(), 403);
        abort_unless($person->matchesInvitation($data['invitation_id'] ?? null), 409, 'This invitation has changed.');
        $payload = $this->payload($call, $user);
        if (! $call->ended_at && in_array($person->status, ['accepted', 'connected'], true) && $person->device_id && $person->device_id !== $data['device_id']) {
            $payload['outcome'] = 'answered_on_another_device';
        } elseif ($person->invitationExpired($call)) {
            $payload['outcome'] = $person->joined_at ? 'left' : 'missed';
        } elseif ($call->status === 'ringing' && $call->ring_until->lte(now())) {
            $payload['outcome'] = $call->started_by === $user->id ? 'unanswered' : 'missed';
        }
        $policy = $this->settings->forConversation($conversation);
        $family = $conversation->conversation_type === 'family';
        $writable = $this->access->allows($user, $conversation, true) && ! $conversation->is_archived
            && $policy['enabled'] && (! $family || $policy['family_enabled']);
        $canCall = $writable && config('chat.shared_experience') && $this->media->ready() && $policy['calls_enabled'] && (! $family || $policy['family_calls_enabled']);

        return response()->json(['call' => $payload, 'followup' => [
            'conversation' => ['id' => $conversation->id, 'title' => $payload['title'], 'can_send' => $writable, 'can_call' => $canCall],
            'family' => (bool) ($family && $conversation->client && $user->canAccessClientPortal($conversation->client)),
            'callbacks' => $family,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, OpsConversation $conversation)
    {
        $data = $this->startData($request);
        $this->access->authorize($request->user(), $conversation, true);
        $this->ensurePreferenceRow($request->user());
        $receipt = DB::transaction(function () use ($request, $conversation, $data) {
            $mode = $data['routing_mode'] ?? 'invited';
            $audience = app(ChatCallAudience::class);
            if ($mode === 'care_team') {
                $responders = $audience->responders($request->user(), $conversation);
                $ids = $audience->availableIds($responders);
            } else {
                $ids = collect($data['participant_ids']);
            }
            $ids = $ids->push($request->user()->id)->unique()->sort()->values();
            $users = $this->lockPeople($ids);
            if ($existing = DB::table('chat_call_start_commands')->where('actor_id', $request->user()->id)->where('mutation_id', $data['mutation_id'])->first()) {
                $this->assertStartReceipt($existing, $conversation, $data);

                return $existing;
            }
            // Older calls have no immutable request receipt. Never reinterpret their key.
            abort_if(ChatCall::where('started_by', $request->user()->id)->where('mutation_id', $data['mutation_id'])->exists(), 409, 'This earlier call must be reviewed in call history.');
            $this->assertCalling($request->user(), $conversation);
            if ($mode === 'care_team') {
                abort_unless($audience->canCallCareTeam($request->user(), $conversation) && $data['participant_ids'] === [], 422, 'Choose the care team or named participants, not both.');
            }
            $available = $audience->availableIds($users);
            if ($mode === 'care_team') {
                // Invitations may ring several responders, but only caller plus winner can join.
                $users = $users->filter(fn ($person) => $person->id === $request->user()->id || $available->contains($person->id));
                abort_if($users->count() < 2, 409, 'The care team is unavailable. Send a message or request a callback.');
            } else {
                abort_unless($users->count() === $ids->count() && $ids->count() >= 2 && $ids->count() <= $this->settings->forConversation($conversation)['max_call_participants'], 422);
            }
            foreach ($users as $user) {
                $this->access->authorize($user, $conversation, true);
                $busy = ChatCallParticipant::where('user_id', $user->id)->whereIn('status', ['accepted', 'connected'])->whereHas('call', fn ($query) => $query->whereNull('ended_at'))->exists();
                abort_if($busy, 409, 'A participant is already in a call.');
                if ($user->id !== $request->user()->id) {
                    abort_unless($available->contains($user->id), 409, 'A participant is unavailable. Send a message or request a callback.');
                }
            }
            $call = ChatCall::create(['id' => (string) Str::uuid(), 'conversation_id' => $conversation->id, 'started_by' => $request->user()->id, 'host_id' => $request->user()->id, 'mutation_id' => $data['mutation_id'], 'routing_mode' => $mode, 'ring_until' => now()->addSeconds(40)]);
            foreach ($users as $user) {
                $caller = $user->id === $request->user()->id;
                $call->participants()->create(['user_id' => $user->id, 'media_identity' => (string) Str::uuid(), 'status' => $caller ? 'accepted' : 'invited', 'device_id' => $caller ? $data['device_id'] : null, 'invitation_expires_at' => $caller ? null : $call->ring_until]);
            }
            $receiptId = DB::table('chat_call_start_commands')->insertGetId([
                'actor_id' => $request->user()->id, 'conversation_id' => $conversation->id,
                'mutation_id' => $data['mutation_id'], 'device_id' => $data['device_id'],
                'payload_hash' => $this->startHash($conversation, $data), 'call_id' => $call->id,
                'invitation_id' => $call->participants()->where('user_id', $request->user()->id)->value('media_identity'),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('chat_call_start_commands')->find($receiptId);
        });

        return response()->json($this->startResult($receipt, $request->user()), 201)->header('Cache-Control', 'private, no-store');
    }

    public function startOutcome(Request $request, OpsConversation $conversation, string $mutation)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);
        $this->access->authorize($request->user(), $conversation, true);
        $receipt = DB::table('chat_call_start_commands')->where('actor_id', $request->user()->id)->where('mutation_id', $mutation)->first();
        if ($receipt) {
            abort_unless($receipt->conversation_id === $conversation->id && $receipt->device_id === $data['device_id'], 409, 'This call attempt belongs to another conversation or device.');
        }

        // An absent receipt is not proof that an in-flight request cannot commit later.
        return response()->json($receipt ? $this->startResult($receipt, $request->user()) : ['attempt_state' => 'unconfirmed', 'call' => null, 'can_join' => false])->header('Cache-Control', 'private, no-store');
    }

    public function cancelStart(Request $request, OpsConversation $conversation, string $mutation)
    {
        $data = $this->startData($request);
        abort_unless($data['mutation_id'] === $mutation, 409);
        $this->access->authorize($request->user(), $conversation, true);
        $this->ensurePreferenceRow($request->user());
        $call = DB::transaction(function () use ($request, $conversation, $data) {
            // Start locks the same actor: cancellation wins either before
            // creation (a tombstone), or after it (the original invitation is denied).
            $this->lockPeople(collect([$request->user()->id]));
            $receipt = DB::table('chat_call_start_commands')->where('actor_id', $request->user()->id)->where('mutation_id', $data['mutation_id'])->first();
            if (! $receipt) {
                abort_if(ChatCall::where('started_by', $request->user()->id)->where('mutation_id', $data['mutation_id'])->exists(), 409);
                DB::table('chat_call_start_commands')->insert([
                    'actor_id' => $request->user()->id, 'conversation_id' => $conversation->id,
                    'mutation_id' => $data['mutation_id'], 'device_id' => $data['device_id'],
                    'payload_hash' => $this->startHash($conversation, $data),
                    'cancelled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return null;
            }
            $this->assertStartReceipt($receipt, $conversation, $data);
            DB::table('chat_call_start_commands')->where('id', $receipt->id)->update(['cancelled_at' => $receipt->cancelled_at ?? now(), 'updated_at' => now()]);
            $call = $receipt->call_id ? ChatCall::whereKey($receipt->call_id)->lockForUpdate()->first() : null;
            $needsCleanup = false;
            if ($call && ! $call->ended_at) {
                $person = $call->participants()->where('user_id', $request->user()->id)->first();
                if ($person && $person->media_identity === $receipt->invitation_id && in_array($person->status, ['accepted', 'connected', 'removing'], true)) {
                    $person->update(['status' => 'removing', 'left_at' => now()]);
                    $needsCleanup = true;
                }
            }

            return $needsCleanup ? $call : null;
        });
        if ($call) {
            try {
                app(ChatCallReconciler::class)->reconcile($call);
            } catch (\Throwable $error) {
                // Committed denial must survive a media outage; scheduled reconciliation retries.
                report($error);
            }
        }

        return response()->json(['attempt_state' => 'cancelled', 'call' => null, 'can_join' => false])->header('Cache-Control', 'private, no-store');
    }

    /**
     * Starts, answers, cancels and availability saves take each person's Chat row, never an
     * exclusive lock on their users row: other modules read that row with FOR SHARE NOWAIT and
     * failed while Chat held it (test server, 30 Oct 2026). The users rows are only share-locked,
     * so a DND or quiet-hours save still waits for the availability check. Users first, then
     * chat_preferences, both in id order, as ChatPersonalPreferences::update() does.
     */
    private function lockPeople(Collection $ids): Collection
    {
        $users = User::whereIn('id', $ids)->orderBy('id')->sharedLock()->get();
        DB::table('chat_preferences')->whereIn('user_id', $ids)->orderBy('user_id')->lockForUpdate()->pluck('user_id');

        return $users;
    }

    /** Made before any lock is taken, so two first saves can't deadlock on the insert. A default row reads as no row. */
    private function ensurePreferenceRow(User $user): void
    {
        DB::table('chat_preferences')->insertOrIgnore(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function startData(Request $request): array
    {
        return $request->validate(['mutation_id' => ['required', 'uuid'], 'device_id' => ['required', 'uuid'], 'routing_mode' => ['sometimes', Rule::in(['invited', 'care_team'])], 'participant_ids' => ['present', 'array', 'max:7'], 'participant_ids.*' => ['required', 'integer', 'distinct']]);
    }

    private function startHash(OpsConversation $conversation, array $data): string
    {
        return hash('sha256', json_encode([$conversation->id, $data['device_id'], $data['routing_mode'] ?? 'invited', collect($data['participant_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all()], JSON_THROW_ON_ERROR));
    }

    private function assertStartReceipt(object $receipt, OpsConversation $conversation, array $data): void
    {
        abort_unless($receipt->conversation_id === $conversation->id && hash_equals($receipt->payload_hash, $this->startHash($conversation, $data)), 409, 'This call attempt has different details. Review the original attempt.');
    }

    private function startResult(object $receipt, User $user): array
    {
        if ($receipt->cancelled_at) {
            return ['attempt_state' => 'cancelled', 'call' => null, 'can_join' => false];
        }
        $call = $receipt->call_id ? ChatCall::find($receipt->call_id) : null;
        $memberId = $call?->conversation->participants()->where('user_id', $user->id)->value('id');
        if ($call && $memberId && DB::table('chat_history_call_exclusions')->where('participant_id', $memberId)->where('call_id', $call->id)->exists()) {
            return ['attempt_state' => 'finished', 'can_join' => false, 'call' => null];
        }
        $person = $call?->participants()->where('user_id', $user->id)->first();
        $canJoin = $call && ! $call->ended_at && ! ($call->status === 'ringing' && $call->ring_until->lte(now()))
            && $person && $person->media_identity === $receipt->invitation_id && $person->device_id === $receipt->device_id
            && in_array($person->status, ['accepted', 'connected'], true);

        return ['attempt_state' => $canJoin ? 'started' : 'finished', 'can_join' => (bool) $canJoin, 'call' => $call ? $this->payload($call, $user) : null];
    }

    public function options(Request $request, OpsConversation $conversation)
    {
        $this->assertCalling($request->user(), $conversation);
        $members = $conversation->members()->pluck('user_id');
        $audience = app(ChatCallAudience::class);
        $people = $audience->people($request->user(), $conversation);
        $available = $audience->availableIds($people);
        $teamAllowed = $audience->canCallCareTeam($request->user(), $conversation);
        $responders = $teamAllowed ? $people->reject(fn ($person) => $person->canAccessClientPortal($conversation->client)) : collect();

        return response()->json(['people' => $people->map(fn ($person) => [
            'id' => $person->id, 'name' => $person->name,
            'available' => $available->contains($person->id),
            'cover' => ! $members->contains($person->id),
        ])->values(), 'care_team' => ['allowed' => $teamAllowed, 'available' => $responders->contains(fn ($person) => $available->contains($person->id))],
            'max_participants' => $this->settings->forConversation($conversation)['max_call_participants']])->header('Cache-Control', 'private, no-store');
    }

    public function join(Request $request, ChatCall $call)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'invitation_id' => ['nullable', 'uuid']]);
        $this->ensurePreferenceRow($request->user());

        return DB::transaction(function () use ($request, $call, $data) {
            $this->lockPeople(collect([$request->user()->id]));
            $locked = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $this->assertCalling($request->user(), $locked->conversation, $locked->status === 'active');
            abort_if($locked->ended_at || ($locked->status === 'ringing' && $locked->ring_until->lte(now())), 409, 'This call has ended.');
            $participant = $locked->participants()->where('user_id', $request->user()->id)->firstOrFail();
            abort_unless($participant->matchesInvitation($data['invitation_id'] ?? null), 409, 'This invitation has changed. Refresh the call before answering.');
            abort_if($participant->status === 'expired' || $participant->invitationExpired($locked), 409, 'This invitation has expired. Ask for a new invitation or send a message.');
            abort_if($locked->routing_mode === 'care_team' && $locked->answered_by && $request->user()->id !== $locked->started_by && $locked->answered_by !== $request->user()->id, 409, 'Another care-team member answered this call.');
            abort_unless(in_array($participant->status, ['invited', 'accepted', 'connected'], true), 403);
            abort_if($participant->device_id && $participant->device_id !== $data['device_id'], 409, 'This call was answered on another device.');
            abort_if(ChatCallParticipant::where('user_id', $request->user()->id)->where('call_id', '!=', $locked->id)->whereIn('status', ['accepted', 'connected'])->whereHas('call', fn ($query) => $query->whereNull('ended_at'))->exists(), 409, 'You are already in another call.');
            if ($locked->routing_mode === 'care_team' && $request->user()->id !== $locked->started_by && ! $locked->answered_by) {
                abort_if($request->user()->canAccessClientPortal($locked->conversation->client), 403);
                abort_unless(app(ChatCallAudience::class)->availableIds(collect([$request->user()]))->contains($request->user()->id), 409, 'You are no longer available to answer this care-team call.');
                $locked->update(['answered_by' => $request->user()->id, 'host_id' => $request->user()->id]);
                $locked->participants()->where('user_id', '!=', $request->user()->id)->where('user_id', '!=', $locked->started_by)
                    ->where('status', 'invited')->update(['status' => 'answered_elsewhere', 'left_at' => now()]);
            }
            $participant->update(['status' => 'accepted', 'device_id' => $data['device_id']]);
            if ($request->user()->id !== $locked->started_by) {
                $locked->update(['status' => 'active']);
            }

            return response()->json(['call' => $this->payload($locked, $request->user()), 'connection' => $this->media->connection($locked, $participant)])->header('Cache-Control', 'private, no-store');
        });
    }

    public function connected(Request $request, ChatCall $call)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'invitation_id' => ['nullable', 'uuid']]);
        $this->access->authorize($request->user(), $call->conversation, true);
        abort_if($call->ended_at, 409);
        $participant = $call->participants()->where('user_id', $request->user()->id)->where('device_id', $data['device_id'])->whereIn('status', ['accepted', 'connected'])->firstOrFail();
        abort_unless($participant->matchesInvitation($data['invitation_id'] ?? null), 409);
        // Browser acknowledgement is a heartbeat only. Signed media events establish connection time.
        $participant->touch();

        return response()->noContent();
    }

    public function leave(Request $request, ChatCall $call)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid'], 'invitation_id' => ['nullable', 'uuid'], 'end_for_everyone' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($request, $call, $data) {
            $locked = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $participant = $locked->participants()->where('user_id', $request->user()->id)->firstOrFail();
            abort_unless($participant->matchesInvitation($data['invitation_id'] ?? null), 409, 'This invitation has changed. Refresh the call before leaving.');
            abort_if($participant->device_id && $participant->device_id !== $data['device_id'], 409, 'This call is active on another device.');
            if ($locked->ended_at || in_array($participant->status, ['left', 'declined', 'revoked', 'answered_elsewhere', 'expired', 'removing', 'removed'], true)) {
                return;
            }
            $end = $data['end_for_everyone'] ?? false;
            abort_if($end && $locked->host_id !== $request->user()->id, 403);
            $remaining = $locked->participants()->where('user_id', '!=', $participant->user_id)->whereIn('status', ['accepted', 'connected'])->orderBy('id')->get();
            $invited = $locked->participants()->where('user_id', '!=', $participant->user_id)->where('status', 'invited')->exists();
            $end = $end || $remaining->isEmpty() || ($remaining->count() === 1 && ! $invited);
            $successor = $locked->host_id === $participant->user_id ? app(ChatCallHost::class)->successor($locked, $remaining) : $locked->host_id;
            $end = $end || $successor === null;
            // Check ownership and tear down media while holding the same lock as join/reconciliation.
            if ($end) {
                $this->media->end($locked);
            } elseif ($participant->status !== 'invited') {
                $this->media->remove($locked, $participant);
            }
            $participant->update(['status' => $participant->status === 'invited' ? 'declined' : 'left', 'left_at' => now()]);
            if ($end) {
                $locked->update(['status' => $locked->status === 'ringing' && $locked->started_by === $participant->user_id ? 'cancelled' : 'ended', 'ended_at' => now()]);
                $locked->participants()->whereIn('status', ['accepted', 'connected', 'invited'])->update(['status' => 'left', 'left_at' => now()]);
            } elseif ($locked->host_id === $participant->user_id) {
                $locked->update(['host_id' => $successor]);
            }
        });

        return response()->noContent();
    }

    public function history(Request $request, OpsConversation $conversation)
    {
        $this->access->authorize($request->user(), $conversation);
        $data = $request->validate(['filter' => ['sometimes', Rule::in(['all', 'missed'])], 'before' => ['nullable', 'uuid']]);
        $query = ChatCall::where('conversation_id', $conversation->id)
            ->whereHas('participants', fn ($participants) => $participants->where('user_id', $request->user()->id));
        $member = $conversation->participants()->where('user_id', $request->user()->id)->first();
        $query->whereNotExists(fn ($boundary) => $boundary->selectRaw('1')->from('chat_history_call_exclusions')
            ->whereColumn('call_id', 'chat_calls.id')->where('participant_id', $member?->id ?? 0));
        if ($data['before'] ?? null) {
            $anchor = (clone $query)->whereKey($data['before'])->firstOrFail();
            $query->where(fn ($earlier) => $earlier->where('created_at', '<', $anchor->created_at)
                ->orWhere(fn ($sameTime) => $sameTime->where('created_at', $anchor->created_at)->where('id', '<', $anchor->id)));
        }
        if (($data['filter'] ?? 'all') === 'missed') {
            $query->where(fn ($finished) => $finished->whereNotNull('ended_at')
                ->orWhereHas('participants', fn ($expired) => $expired->where('user_id', $request->user()->id)->where('status', 'expired')))
                ->where('started_by', '!=', $request->user()->id)
                ->whereHas('participants', fn ($participants) => $participants->where('user_id', $request->user()->id)
                    ->whereNull('joined_at')->whereNotIn('status', ['declined', 'revoked', 'answered_elsewhere', 'removing', 'removed']));
        }
        $calls = $query->with(['conversation.client', 'participants.user'])->orderByDesc('created_at')->orderByDesc('id')->limit(51)->get();
        $page = $calls->take(50);

        return response()->json(['calls' => $page->map(fn ($call) => $this->payload($call, $request->user())),
            'next_cursor' => $calls->count() > 50 ? $page->last()->id : null]);
    }

    private function assertCalling(User $user, OpsConversation $conversation, bool $continuing = false): void
    {
        $this->access->authorize($user, $conversation, true);
        $settings = $this->settings->forConversation($conversation);
        abort_unless(config('chat.shared_experience') && ! $conversation->is_archived && $this->media->ready(), 503, 'Voice calls are not available yet.');
        if (! $continuing) {
            abort_unless($settings['enabled'] && $settings['calls_enabled'], 503, 'Voice calls are disabled for this conversation.');
            abort_if($conversation->conversation_type === 'family' && (! $settings['family_enabled'] || ! $settings['family_calls_enabled']), 403);
        }
    }

    private function payload(ChatCall $call, User $user): array
    {
        $call->loadMissing(['conversation', 'participants.user']);
        $participant = $call->participants->firstWhere('user_id', $user->id);
        $visible = $call->participants->filter(fn ($person) => $person->user && $this->access->allows($person->user, $call->conversation))->values();
        $caller = $visible->firstWhere('user_id', $participant?->status === 'invited' ? ($participant->invited_by ?? $call->started_by) : $call->started_by)?->user;
        $client = $call->conversation->client;
        $clientName = $client ? trim($client->first_name.' '.$client->last_name) : null;
        $presentation = null;
        if ($caller) {
            $family = $call->conversation->conversation_type === 'family';
            $portalCaller = $family && $client && $caller->canAccessClientPortal($client);
            $group = ! $family && $call->conversation->conversation_type === 'group';
            $detail = $group ? $caller->name.' is inviting you' : 'Colleague';
            if ($family) {
                $detail = $portalCaller ? 'Approved family participant' : 'Care-team member';
                if ($portalCaller) {
                    // Read only the current linked caller's relationship. Old,
                    // removed or conflicting records must not become an identity claim.
                    $relationships = $client->nextOfKins()->where('user_id', $caller->id)->pluck('relationship')
                        ->map(fn ($value) => trim((string) $value))->filter()->unique()->values();
                    if ($relationships->count() === 1 && $clientName) {
                        $relationship = $relationships->first();
                        $plain = Str::lower($relationship);
                        $detail = in_array($plain, ['mother', 'father', 'parent', 'sister', 'brother', 'daughter', 'son', 'partner', 'spouse', 'guardian', 'grandparent'], true)
                            ? $clientName.'’s '.$plain : $clientName.' · '.$relationship;
                    }
                } elseif (! $call->conversation->participants()->where('user_id', $caller->id)->exists()) {
                    $detail = 'Authorised care-team cover';
                }
            }
            $audience = $visible->filter(fn ($person) => in_array($person->status, ['invited', 'accepted', 'connected'], true));
            $others = $audience->where('user_id', '!=', $user->id)->map(fn ($person) => $person->user->name)->values();
            $audienceLabel = $others->isEmpty() ? 'Approved call participants'
                : ($others->count() === 1 ? 'Just you and '.$others->first() : 'Invited: '.$others->implode(', ').' and you');
            if ($call->routing_mode === 'care_team' && ! $call->answered_by && ! $call->ended_at) {
                $audienceLabel = 'Calling the available care team · First responder answers';
            }
            $presentation = [
                'kind' => $family ? ($portalCaller ? 'family' : 'care_team') : ($group ? 'group' : 'staff'),
                'caller_name' => $group ? ($call->conversation->title ?: 'Group voice call') : $caller->name,
                'caller_detail' => $detail,
                'audience' => ($family && $clientName ? 'About '.$clientName.' · ' : '').$audienceLabel,
                'viewer_is_family' => $family && $client && $user->canAccessClientPortal($client),
            ];
        }

        return ['id' => $call->id, 'conversation_id' => $call->conversation_id, 'client_id' => $call->conversation->client_id, 'title' => $call->routing_mode === 'care_team' ? 'Care team'.($clientName ? ' · '.$clientName : '') : ($call->conversation->title ?: 'Voice call'),
            'routing_mode' => $call->routing_mode ?? 'invited',
            'client_name' => $clientName,
            'presentation' => $presentation,
            'can_record_note' => $call->conversation->client
                && $user->canDo('progress_notes.create')
                && Gate::forUser($user)->allows('view', $call->conversation->client)
                && app(ClientFamilyCommunicationAccess::class)->canManage($user, $call->conversation->client),
            'status' => $call->status, 'host_id' => $call->host_id, 'started_by' => $call->started_by,
            'connected_at' => $call->connected_at?->toISOString(), 'ended_at' => $call->ended_at?->toISOString(),
            'ring_until' => $call->ring_until->toISOString(), 'created_at' => $call->created_at?->toISOString(),
            'my_status' => $participant?->status,
            'my_invitation_id' => $participant?->media_identity,
            'max_participants' => $this->settings->forConversation($call->conversation)['max_call_participants'],
            'direction' => $call->started_by === $user->id ? 'outgoing' : 'incoming',
            'outcome' => $participant ? ChatCallOutcome::forParticipant($call, $participant) : null,
            'participants' => $visible->map(fn ($participant) => ['id' => $participant->user_id, 'name' => $participant->user->name, 'status' => $participant->status,
                'invitation_id' => $participant->media_identity])->values()];
    }
}
