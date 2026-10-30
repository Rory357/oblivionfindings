<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatCallParticipant;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatCallAudience;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\SelfHostedCalling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChatCallPeopleController extends Controller
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatCallAudience $audience, private readonly ChatSettings $settings, private readonly SelfHostedCalling $media) {}

    private function actor(Request $request, ChatCall $call, string $device): ChatCallParticipant
    {
        $this->access->authorize($request->user(), $call->conversation, true);
        abort_if($call->ended_at || $call->status !== 'active', 409, 'This call is no longer active.');
        $actor = $call->participants()->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless(in_array($actor->status, ['accepted', 'connected'], true) && $actor->device_id === $device, 409, 'Use the device that is connected to this call.');
        if ($request->isMethod('POST')) {
            abort_unless($actor->matchesInvitation($request->input('actor_invitation_id')), 409, 'Your participation in this call has changed. Refresh the call.');
        }

        return $actor;
    }

    private function assertInviting(ChatCall $call): void
    {
        $policy = $this->settings->forConversation($call->conversation);
        abort_unless(config('chat.shared_experience') && $this->media->ready() && ! $call->conversation->is_archived
            && $policy['enabled'] && $policy['calls_enabled']
            && ($call->conversation->conversation_type !== 'family' || ($policy['family_enabled'] && $policy['family_calls_enabled'])), 403, 'New call invitations are unavailable.');
        abort_if($call->routing_mode === 'care_team' && ! $call->answered_by, 409, 'Wait for the care team to answer first.');
    }

    public function options(Request $request, ChatCall $call)
    {
        $data = $request->validate(['device_id' => ['required', 'uuid']]);
        $this->actor($request, $call, $data['device_id']);
        $this->assertInviting($call);
        $reserved = $call->participants()->get()->filter(fn ($person) => in_array($person->status, ['accepted', 'connected', 'removing'], true)
            || ($person->status === 'invited' && ! $person->invitationExpired($call)));
        $people = $this->audience->people($request->user(), $call->conversation)->whereNotIn('id', $reserved->pluck('user_id'));
        $available = $this->audience->availableIds($people);

        return response()->json(['people' => $people->map(fn ($person) => ['id' => $person->id, 'name' => $person->name, 'available' => $available->contains($person->id)])->values(),
            'reserved' => $reserved->count(), 'max_participants' => $this->settings->forConversation($call->conversation)['max_call_participants']])->header('Cache-Control', 'private, no-store');
    }

    public function invite(Request $request, ChatCall $call)
    {
        $data = $request->validate(['mutation_id' => ['required', 'uuid'], 'device_id' => ['required', 'uuid'], 'actor_invitation_id' => ['required', 'uuid'],
            'participant_ids' => ['required', 'array', 'min:1', 'max:7'], 'participant_ids.*' => ['required', 'integer', 'distinct']]);
        $data['participant_ids'] = collect($data['participant_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        DB::transaction(function () use ($request, $call, $data): void {
            // Match join/start's user-before-call lock order; reserve all recipients together.
            User::whereIn('id', [...$data['participant_ids'], $request->user()->id])->orderBy('id')->lockForUpdate()->get();
            $call = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $call->conversation, true);
            if ($this->receipt($request, $call, 'invite', $data)) {
                return;
            }
            $this->actor($request, $call, $data['device_id']);
            $this->assertInviting($call);
            $people = $this->audience->people($request->user(), $call->conversation)->whereIn('id', $data['participant_ids']);
            abort_unless($people->count() === count($data['participant_ids']), 403, 'A selected person is no longer eligible for this conversation.');
            $available = $this->audience->availableIds($people);
            abort_unless($available->count() === $people->count(), 409, 'A selected person is unavailable. Refresh the list before inviting.');
            $participants = $call->participants()->get();
            $reserved = $participants->filter(fn ($person) => in_array($person->status, ['accepted', 'connected', 'removing'], true)
                || ($person->status === 'invited' && ! $person->invitationExpired($call)));
            abort_if($reserved->whereIn('user_id', $data['participant_ids'])->isNotEmpty(), 409, 'A selected person is already in this call or has an open invitation.');
            abort_if($reserved->count() + $people->count() > $this->settings->forConversation($call->conversation)['max_call_participants'], 409, 'This call has reached its participant limit.');
            foreach ($people as $person) {
                $prior = $participants->firstWhere('user_id', $person->id);
                $values = ['media_identity' => (string) Str::uuid(), 'status' => 'invited', 'device_id' => null, 'left_at' => null,
                    'invitation_version' => $prior ? $prior->invitation_version + 1 : 1, 'invited_by' => $request->user()->id, 'invitation_expires_at' => now()->addSeconds(40)];
                if ($prior) {
                    $prior->update($values);
                } else {
                    $call->participants()->create(['user_id' => $person->id, ...$values]);
                }
            }
            // This explicit selection creates a group; closed care-team invitations stay closed.
            if ($call->routing_mode === 'care_team') {
                $call->update(['routing_mode' => 'invited']);
            }
            $this->saveReceipt($request, $call, 'invite', $data, true);
        });

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function remove(Request $request, ChatCall $call)
    {
        $data = $request->validate(['mutation_id' => ['required', 'uuid'], 'device_id' => ['required', 'uuid'], 'actor_invitation_id' => ['required', 'uuid'],
            'participant_id' => ['required', 'integer'], 'invitation_id' => ['required', 'uuid']]);
        $pending = DB::transaction(function () use ($request, $call, $data) {
            $call = ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($request->user(), $call->conversation, true);
            if ($receipt = $this->receipt($request, $call, 'remove', $data)) {
                return $receipt->completed_at ? null : $receipt;
            }
            $this->actor($request, $call, $data['device_id']);
            abort_unless($call->host_id === $request->user()->id && (int) $data['participant_id'] !== $request->user()->id, 403);
            $person = $call->participants()->where('user_id', $data['participant_id'])->firstOrFail();
            abort_unless(hash_equals($person->media_identity, $data['invitation_id']), 409, 'This person has a newer invitation. Refresh the call before removing them.');
            abort_unless(in_array($person->status, ['invited', 'accepted', 'connected'], true), 409, 'This person is no longer in the call.');
            // Commit denial before contacting media. A reconnect cannot pass the gateway during teardown.
            $person->update(['status' => 'removing', 'left_at' => now()]);

            return $this->saveReceipt($request, $call, 'remove', $data, false);
        });
        if ($pending) {
            $person = ChatCallParticipant::where('call_id', $call->id)->where('media_identity', $pending->invitation_id)->first();
            if ($person) {
                $this->media->remove($call, $person);
            }
            DB::transaction(function () use ($call, $pending): void {
                ChatCall::whereKey($call->id)->lockForUpdate()->firstOrFail();
                ChatCallParticipant::where('call_id', $call->id)->where('media_identity', $pending->invitation_id)->where('status', 'removing')->update(['status' => 'removed', 'left_at' => now()]);
                DB::table('chat_call_people_commands')->where('id', $pending->id)->update(['completed_at' => now(), 'updated_at' => now()]);
            });
        }

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    private function receipt(Request $request, ChatCall $call, string $action, array $data): ?object
    {
        $receipt = DB::table('chat_call_people_commands')->where('call_id', $call->id)->where('actor_id', $request->user()->id)->where('mutation_id', $data['mutation_id'])->first();
        if ($receipt) {
            abort_unless($receipt->action === $action && hash_equals($receipt->payload_hash, hash('sha256', json_encode($data))), 409, 'This request was already used for a different call action.');
        }

        return $receipt;
    }

    private function saveReceipt(Request $request, ChatCall $call, string $action, array $data, bool $complete): object
    {
        $id = DB::table('chat_call_people_commands')->insertGetId(['call_id' => $call->id, 'actor_id' => $request->user()->id, 'mutation_id' => $data['mutation_id'],
            'action' => $action, 'payload_hash' => hash('sha256', json_encode($data)), 'target_id' => $data['participant_id'] ?? null,
            'invitation_id' => $data['invitation_id'] ?? null, 'completed_at' => $complete ? now() : null, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('chat_call_people_commands')->find($id);
    }
}
