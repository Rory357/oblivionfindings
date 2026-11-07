<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatAudience;
use App\Services\Chat\ChatProjection;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatSearchController extends Controller
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatProjection $projection) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['messages', 'files', 'people', 'conversations'])],
            'q' => ['nullable', 'string', 'max:200'],
            'client' => ['nullable', 'integer', 'min:1'],
            'conversation' => ['nullable', 'integer', 'min:1'],
            'sender' => ['nullable', 'integer', 'min:1'],
            'date' => ['nullable', Rule::in(['any', 'today'])],
            'before' => ['nullable', 'integer', 'min:1'],
            'through' => ['nullable', 'integer', 'min:1'],
        ]);
        $viewer = $request->user();
        $staff = app(OpsMessageVisibilityService::class)->isCurrentStaff($viewer);
        $client = isset($data['client']) ? Client::findOrFail($data['client']) : null;
        abort_unless($staff || ($client && $viewer->canAccessClientPortal($client)), 403);
        if ($client) {
            abort_unless($viewer->canAccessClientPortal($client) || app(ClientFamilyCommunicationAccess::class)->canView($viewer, $client), 403);
        }
        // Cover conversations are narrowed in SQL to clients canView() could accept; allows() stays the final check.
        $family = app(ClientFamilyCommunicationAccess::class);
        $conversations = $this->access->filterAllowed($viewer, OpsConversation::query()
            ->where(fn ($query) => $query->whereHas('participants', fn ($members) => $members->where('user_id', $viewer->id))
                ->when($staff, fn ($cover) => $cover->orWhere(fn ($familyCover) => $familyCover->where('conversation_type', 'family')->where('care_team_cover', true)
                    ->whereHas('client', fn ($clients) => $family->scopeViewableClients($clients, $viewer)))))
            ->when($client, fn ($query) => $query->where('client_id', $client->id)->where('conversation_type', 'family'))
            ->with(['client', 'participants.user'])->orderByDesc('updated_at')->get())->keyBy('id');
        if (isset($data['conversation'])) {
            abort_unless($conversations->has($data['conversation']), 403);
        }
        $members = $conversations->map(fn ($conversation) => app(ChatAudience::class)->members($conversation));
        $labels = $conversations->map(fn ($conversation) => [
            'id' => $conversation->id,
            'title' => $conversation->title ?: $members[$conversation->id]->filter(fn ($member) => $member->user_id !== $viewer->id)->map(fn ($member) => $member->user?->name)->filter()->join(', '),
            'type' => $conversation->is_managed_channel ? 'channel' : $conversation->conversation_type,
        ]);
        $people = $conversations->flatMap(fn ($conversation) => $members[$conversation->id]->filter(fn ($member) => $member->user)->map(fn ($member) => [
            'id' => $member->user_id, 'name' => $member->user->name, 'conversation_id' => $conversation->id,
        ]))->unique('id')->values();
        $options = ['conversations' => $labels->values(), 'people' => $people->map(fn ($person) => ['id' => $person['id'], 'name' => $person['name']])];
        $selected = isset($data['conversation']) ? $conversations->only($data['conversation']) : $conversations;
        $term = trim($data['q'] ?? '');
        $before = isset($data['before']) ? (int) $data['before'] : null;
        $through = isset($data['through']) ? (int) $data['through'] : null;

        if (in_array($data['kind'], ['people', 'conversations'], true)) {
            $rows = $data['kind'] === 'people'
                ? $selected->flatMap(fn ($conversation) => $members[$conversation->id]->filter(fn ($member) => $member->user)->map(fn ($member) => [
                    'id' => $member->user_id, 'kind' => 'person', 'name' => $member->user->name, 'conversation' => $labels[$conversation->id],
                ]))->unique('id')->filter(fn ($person) => $term === '' || mb_stripos($person['name'], $term) !== false)
                : $labels->only($selected->keys()->all())->filter(fn ($conversation) => $term === '' || mb_stripos($conversation['title'], $term) !== false)->map(fn ($conversation) => [
                    'id' => $conversation['id'], 'kind' => 'conversation', 'conversation' => $conversation,
                ]);
            $rows = $rows->sortByDesc('id')->values();
            $older = $through && $rows->contains(fn ($row) => $row['id'] < $through) ? $through : null;
            $range = $rows->filter(fn ($row) => (! $before || $row['id'] < $before) && (! $through || $row['id'] >= $through));
            $items = $range->take(50)->values();
            $floor = $items->last()['id'] ?? null;

            return $this->response($items, $range->count() > 50 ? $floor : null, $floor, $older, $options);
        }

        $query = $this->access->messagesAcross($viewer, $selected)
            ->when($data['sender'] ?? null, fn ($query, $sender) => $query->where('ops_messages.sender_id', $sender));
        if (($data['date'] ?? 'any') === 'today') {
            $start = CarbonImmutable::now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay();
            $query->where('ops_messages.created_at', '>=', $start->utc())->where('ops_messages.created_at', '<', $start->addDay()->utc());
        }
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        if ($data['kind'] === 'files') {
            $query->where(fn ($files) => $files->whereExists(fn ($file) => $file->selectRaw('1')->from('chat_attachments')->whereColumn('message_id', 'ops_messages.id'))
                ->orWhereJsonLength('ops_messages.attachments', '>', 0));
        }
        if ($term !== '') {
            $query->where(fn ($match) => $match->whereRaw("ops_messages.content LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereExists(fn ($file) => $file->selectRaw('1')->from('chat_attachments')->whereColumn('message_id', 'ops_messages.id')->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]))
                ->orWhereJsonLength('ops_messages.attachments', '>', 0));
        }
        $older = $through && (clone $query)->where('ops_messages.id', '<', $through)->exists() ? $through : null;
        $rows = $query->when($before, fn ($query) => $query->where('ops_messages.id', '<', $before))
            ->when($through, fn ($query) => $query->where('ops_messages.id', '>=', $through))
            ->with(['conversation.client', 'conversation.participants.user', 'sender', 'reactions'])->orderByDesc('ops_messages.id')->limit(501)->get();
        $items = [];
        $floor = null;
        $candidates = $rows->take(500);
        // Recheck current membership and each message's history boundary before projecting any result
        // content: one allows() and one boundary query per conversation instead of per row.
        $visibleIds = [];
        foreach ($candidates->groupBy('conversation_id') as $group) {
            $conversation = $group->first()->conversation;
            if ($this->access->allows($viewer, $conversation)) {
                foreach ($this->access->messages($conversation, $viewer)->whereIn('id', $group->pluck('id'))->pluck('id') as $id) {
                    $visibleIds[(int) $id] = true;
                }
            }
        }
        // Projected in batches (same output as one message at a time), in scan order.
        foreach ($candidates->chunk(100) as $chunk) {
            $visible = $chunk->filter(fn ($message) => isset($visibleIds[$message->id]))->values();
            $projected = $visible->isEmpty() ? collect() : $this->projection->messages($visible, $viewer)->keyBy('id');
            foreach ($chunk as $message) {
                $floor = $message->id;
                if (! isset($visibleIds[$message->id])) {
                    continue;
                }
                $projection = $projected[$message->id];
                if ($term !== '') {
                    $searchable = collect($projection['attachments'])->pluck('name')->concat(collect($projection['legacy_attachments'])->pluck('name'))->prepend($projection['content']);
                    if (! $searchable->contains(fn ($text) => mb_stripos($text ?? '', $term) !== false)) {
                        continue;
                    }
                }
                $items[] = ['id' => $message->id, 'kind' => $data['kind'] === 'files' ? 'file' : 'message', 'conversation' => $labels[$message->conversation_id], 'message' => $projection];
                if (count($items) === 50) {
                    break 2;
                }
            }
        }

        return $this->response($items, $floor && $rows->contains(fn ($row) => $row->id < $floor) ? $floor : null, $floor, $older, $options);
    }

    private function response($items, ?int $next, ?int $floor, ?int $older, array $options)
    {
        return response()->json(['items' => $items, 'next_before' => $next, 'scan_floor' => $floor, 'older_before' => $older, 'options' => $options])->header('Cache-Control', 'private, no-store');
    }
}
