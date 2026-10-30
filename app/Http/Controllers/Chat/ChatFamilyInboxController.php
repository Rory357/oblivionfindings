<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatAudience;
use App\Services\Chat\ChatCallbacks;
use App\Services\Chat\ChatFamilyResponse;
use App\Services\Chat\ChatInboxWindow;
use App\Services\Chat\ChatSettings;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ChatFamilyInboxController extends Controller
{
    public function __invoke(Request $request, ChatAccess $access, OpsMessageVisibilityService $staff, ChatSettings $settings)
    {
        $data = $request->validate([
            'client' => ['nullable', 'integer', 'min:1'], 'selected' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', Rule::in(['open', 'mine', 'waiting', 'callbacks', 'resolved'])],
            'before' => ['nullable', 'integer', 'min:1'], 'through' => ['nullable', 'integer', 'min:1'],
            'minimum' => ['nullable', 'integer', 'min:1', 'max:10000'], 'revision' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $actor = $request->user();
        abort_unless($staff->isCurrentStaff($actor), 403);
        $client = isset($data['client']) ? Client::findOrFail($data['client']) : null;
        if ($client) {
            abort_unless(! $actor->canAccessClientPortal($client) && app(ClientFamilyCommunicationAccess::class)->canView($actor, $client), 403);
        }
        $conversations = OpsConversation::where('conversation_type', 'family')->where('is_archived', false)
            ->whereHas('client', fn ($query) => $query->whereIn('site_id', app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['family_portal.viewAny', 'family_portal.manage'])))
            ->when($client, fn ($query) => $query->where('client_id', $client->id))
            ->where(fn ($query) => $query->where('care_team_cover', true)->orWhereHas('participants', fn ($members) => $members->where('user_id', $actor->id)))
            ->with(['client', 'participants.user'])->orderByDesc('id')->get();
        // Portal linkage and family authority depend only on the client; decide each once per client.
        $portal = [];
        $conversations = $access->filterAllowed($actor, $conversations->filter(fn ($conversation) => ! ($portal[$conversation->client_id] ??= $actor->canAccessClientPortal($conversation->client))));
        // Callback counts obey the same membership boundary as the callback dialog.
        $callbacks = DB::table('chat_callbacks as cb')->whereIn('cb.conversation_id', $conversations->pluck('id'))->whereIn('cb.status', ChatCallbacks::ACTIVE)
            ->leftJoin('ops_conversation_participants as member', fn ($join) => $join->on('member.conversation_id', '=', 'cb.conversation_id')->where('member.user_id', $actor->id))
            ->where(fn ($history) => $history->whereNull('member.history_after_id')->orWhereColumn('cb.id', '>', 'member.callback_history_after_id'))
            ->selectRaw('cb.conversation_id, count(*) as total')->groupBy('cb.conversation_id')->pluck('total', 'conversation_id');
        $matches = fn ($conversation, $filter) => match ($filter) {
            'mine' => (int) $conversation->assigned_to === (int) $actor->id && $conversation->queue_status !== 'resolved',
            'callbacks' => ($callbacks[$conversation->id] ?? 0) > 0,
            default => $conversation->queue_status === $filter,
        };
        $counts = collect(['open', 'mine', 'waiting', 'callbacks', 'resolved'])->mapWithKeys(fn ($filter) => [$filter => $conversations->filter(fn ($conversation) => $matches($conversation, $filter))->count()]);
        $matching = $conversations->filter(fn ($conversation) => $matches($conversation, $data['filter'] ?? 'open'));
        $policies = [];
        $clocks = [];
        $priority = [];
        foreach ($matching as $conversation) {
            $site = $conversation->client->site_id;
            $policy = $policies[$site] ??= $settings->read($site);
            $visibleRequest = $conversation->queue_status === 'open' && $conversation->family_response_started_at && $conversation->family_response_message_id
                && $access->messages($conversation, $actor, true)->whereKey($conversation->family_response_message_id)->exists();
            // Only a visible pending request needs its clock to sort; other clocks are projected for the page window only.
            $clock = $visibleRequest ? ($clocks[$conversation->id] = app(ChatFamilyResponse::class)->project($conversation, $actor, $policy)) : null;
            // A configured deadline includes service hours/holidays. Without one, use the actual
            // unanswered request time, never invent a response promise or expose hidden history.
            $priority[$conversation->id] = [$visibleRequest ? 0 : 1,
                $visibleRequest ? ($clock['due_at'] ?? $conversation->family_response_started_at->toISOString()) : '',
                $visibleRequest ? $conversation->family_response_started_at->toISOString() : '', -$conversation->id];
        }
        $ordered = $matching->sortBy(fn ($conversation) => $priority[$conversation->id])->values();
        $revision = hash('sha256', json_encode([$actor->id, $client?->id, $data['filter'] ?? 'open',
            $ordered->map(fn ($conversation) => [$conversation->id, $priority[$conversation->id]])->all()], JSON_THROW_ON_ERROR));
        $window = app(ChatInboxWindow::class)->page($ordered->pluck('id')->map(fn ($id) => (int) $id)->all(), $data, $revision);
        $rows = collect($window['ids'])->map(fn ($id) => $matching->firstWhere('id', $id));
        foreach ($rows as $conversation) {
            $clocks[$conversation->id] ??= app(ChatFamilyResponse::class)->project($conversation, $actor, $policies[$conversation->client->site_id]);
        }
        $names = User::whereIn('id', $rows->pluck('assigned_to')->filter())->pluck('name', 'id');
        $items = $rows->map(function ($conversation) use ($callbacks, $names, $policies, $clocks) {
            $relatives = app(ChatAudience::class)->members($conversation)->filter(fn ($member) => $member->user->canAccessClientPortal($conversation->client));
            $policy = $policies[$conversation->client->site_id];

            return ['id' => $conversation->id, 'title' => $relatives->map(fn ($member) => $member->user->name)->join(', ') ?: 'Family conversation',
                'client' => ['id' => $conversation->client_id, 'name' => trim($conversation->client->first_name.' '.$conversation->client->last_name)],
                'status' => $conversation->queue_status, 'assigned_to' => $conversation->assigned_to ? (int) $conversation->assigned_to : null,
                'owner_name' => $conversation->assigned_to ? ($names[$conversation->assigned_to] ?? 'Former care-team member') : null,
                'callback_count' => (int) ($callbacks[$conversation->id] ?? 0), 'response_expectation' => $policy['response_expectation'],
                'response_clock' => $clocks[$conversation->id]];
        })->values();

        return response()->json(['items' => $items, 'counts' => $counts, 'revision' => $window['revision'], 'next_before' => $window['next_before'], 'scan_floor' => $window['scan_floor'], 'older_before' => $window['older_before'],
            'selected_available' => isset($data['selected']) ? $conversations->contains('id', (int) $data['selected']) : null,
            'settings' => collect($settings->read($client?->site_id))->only(['enabled', 'family_enabled', 'files_enabled', 'max_file_mb', 'max_files', 'response_expectation'])])->header('Cache-Control', 'private, no-store');
    }
}
