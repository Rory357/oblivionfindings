<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatCallAudience;
use App\Services\Chat\ChatCallbacks;
use App\Services\Chat\ChatFamilyContacts;
use App\Services\Chat\ChatFamilyResponse;
use App\Services\Chat\ChatProjection;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\SelfHostedCalling;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ChatFamilyHomeController extends Controller
{
    public function conversation(Request $request, Client $client, OpsConversation $conversation, ChatAccess $access, ChatProjection $projection)
    {
        $actor = $request->user();
        abort_unless($actor->canAccessClientPortal($client) && $conversation->conversation_type === 'family' && (int) $conversation->client_id === (int) $client->id, 403);
        $access->authorize($actor, $conversation);

        return response()->json(['actor_id' => (int) $actor->id, 'client_id' => (int) $client->id,
            'conversation' => collect($projection->conversation($conversation, $actor))->only(['id', 'title', 'type', 'client', 'version', 'can_send', 'can_call', 'can_manage_people'])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, Client $client, ChatFamilyContacts $contacts, ChatAccess $access, ChatSettings $settings, ChatCallAudience $calls, ChatCallbacks $callbacks, ClientFamilyCommunicationAccess $family)
    {
        $actor = $request->user();
        abort_unless($actor->canAccessClientPortal($client), 403);
        $policy = $settings->read($client->site_id);
        $enabled = $policy['enabled'] && $policy['family_enabled'];
        $calling = $enabled && $policy['calls_enabled'] && $policy['family_calls_enabled'] && app(SelfHostedCalling::class)->ready();
        $named = $contacts->namedPeople($client);
        $offDuty = DB::table('chat_preferences')->whereIn('user_id', $named->pluck('id'))->where('availability', 'off_duty')->pluck('user_id');
        $responders = $family->candidateStaffQuery($client, true)->get()->filter(fn ($person) => $family->canManage($person, $client));
        $available = $calling ? $calls->availableIds($responders) : collect();
        $conversations = OpsConversation::where('client_id', $client->id)->where('conversation_type', 'family')
            ->where('is_archived', false)
            ->whereHas('participants', fn ($members) => $members->where('user_id', $actor->id)->where('is_archived_for_user', false))
            ->with(['participants.user', 'client'])->orderByDesc('updated_at')->get()
            ->filter(fn ($conversation) => $access->allows($actor, $conversation))->values();
        $requests = $conversations->flatMap(function ($conversation) use ($actor, $callbacks) {
            return $callbacks->visible($conversation, $actor)->whereIn('status', ChatCallbacks::ACTIVE)->orderByDesc('id')->get()
                ->map(fn ($row) => ['id' => (int) $row->id, 'conversation_id' => $conversation->id, 'status' => $row->status,
                    'preferred_time' => $row->preferred_time, 'agreed_time' => $row->agreed_time]);
        })->sortByDesc('id')->values();
        $team = $contacts->privateConversation($client, $actor, null);
        // This synthetic empty conversation supplies only the configured response
        // target; no pending-message clock or private conversation is fabricated.
        $response = app(ChatFamilyResponse::class)->project(new OpsConversation(['conversation_type' => 'family', 'queue_status' => 'open']), $actor, $policy);

        return response()->json([
            'actor_id' => (int) $actor->id,
            'client_id' => (int) $client->id,
            'clients' => $actor->portalClients()->get()->filter(fn ($entry) => $actor->canAccessClientPortal($entry))
                ->map(fn ($entry) => ['id' => $entry->id, 'name' => trim($entry->first_name.' '.$entry->last_name)])->values(),
            'enabled' => $enabled,
            'care_team' => ['conversation_id' => $team?->id, 'calling_enabled' => $calling, 'available' => $available->isNotEmpty()],
            'response' => $response,
            'response_expectation' => $policy['response_expectation'],
            'contacts' => $named->map(fn ($person) => ['id' => $person->id, 'name' => $person->name,
                'role' => (int) $client->key_worker_id === (int) $person->id ? 'Key worker' : 'Care-team member',
                'available' => $available->contains($person->id), 'off_duty' => $offDuty->contains($person->id),
                'conversation_id' => $contacts->privateConversation($client, $actor, (int) $person->id)?->id])->values(),
            'conversations' => $conversations->map(fn ($conversation) => ['id' => $conversation->id, 'title' => $conversation->title ?: 'Care team'])->values(),
            'callbacks' => $requests,
        ])->header('Cache-Control', 'private, no-store');
    }
}
