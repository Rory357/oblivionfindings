<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatProjection;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChatCollectionController extends Controller
{
    public function __construct(private readonly ChatAccess $access, private readonly ChatProjection $projection) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['saved', 'pinned', 'media'])],
            'conversation' => ['nullable', 'integer', 'min:1'],
            'client' => ['nullable', 'integer', 'min:1'],
            'before' => ['nullable', 'integer', 'min:1'],
            'through' => ['nullable', 'integer', 'min:1'],
        ]);
        $viewer = $request->user();
        $conversation = isset($data['conversation']) ? OpsConversation::findOrFail($data['conversation']) : null;
        $client = isset($data['client']) ? Client::findOrFail($data['client']) : null;
        if ($client) {
            abort_unless($viewer->canAccessClientPortal($client) || app(ClientFamilyCommunicationAccess::class)->canView($viewer, $client), 403);
        }
        if ($conversation) {
            $this->access->authorize($viewer, $conversation);
            abort_if($client && $conversation->client_id !== $client->id, 403);
        } else {
            abort_unless($data['kind'] === 'saved', 422, 'Choose a conversation first.');
            abort_unless($client || app(OpsMessageVisibilityService::class)->isCurrentStaff($viewer), 403);
        }

        $query = ($conversation ? $this->access->messages($conversation, $viewer) : OpsMessage::query())
            ->when($conversation, fn ($q) => $q->where('conversation_id', $conversation->id))
            ->when($client, fn ($q) => $q->where('client_id', $client->id))
            ->when($data['kind'] === 'saved', fn ($q) => $q->whereExists(fn ($saved) => $saved->selectRaw('1')->from('chat_saved_messages')->whereColumn('message_id', 'ops_messages.id')->where('user_id', $viewer->id)))
            ->when($data['kind'] === 'pinned', fn ($q) => $q->where('is_pinned', true))
            ->when($data['kind'] === 'media', fn ($q) => $q->where(fn ($media) => $media
                ->whereExists(fn ($files) => $files->selectRaw('1')->from('chat_attachments')->whereColumn('message_id', 'ops_messages.id'))
                ->orWhereJsonLength('attachments', '>', 0)))
            ->with(['conversation.client', 'conversation.participants.user', 'sender', 'reactions'])
            ->orderByDesc('id');

        // Refresh the range already read without dropping its oldest items when
        // new saves/pins arrive. Older additions remain discoverable by paging.
        $through = isset($data['through']) ? (int) $data['through'] : null;
        $olderBefore = $through && (clone $query)->where('id', '<', $through)->exists() ? $through : null;
        $query
            ->when($data['before'] ?? null, fn ($q, $before) => $q->where('id', '<', $before))
            ->when($through, fn ($q, $floor) => $q->where('id', '>=', $floor));

        // Bound each scan, but advance over revoked/removed items as well. Never
        // return inaccessible titles, counts or contents from a saved reference.
        $rows = $query->limit(501)->get();
        $items = [];
        $checked = [];
        $titles = [];
        $last = null;
        foreach ($rows->take(500) as $message) {
            $last = $message->id;
            $thread = $message->conversation;
            if (! $thread) {
                continue;
            }
            $checked[$thread->id] ??= $this->access->allows($viewer, $thread);
            if (! $checked[$thread->id] || ! $this->access->messages($thread, $viewer)->whereKey($message->id)->exists()) {
                continue;
            }
            $titles[$thread->id] ??= $this->projection->conversation($thread, $viewer)['title'];
            $items[] = ['conversation_id' => $thread->id, 'title' => $titles[$thread->id], 'message' => $this->projection->message($message, $viewer)];
            if (count($items) === 50) {
                break;
            }
        }

        return response()->json([
            'items' => $items,
            'next_before' => $last && $rows->contains(fn ($row) => $row->id < $last) ? $last : null,
            'scan_floor' => $last,
            'older_before' => $olderBefore,
        ])->header('Cache-Control', 'private, no-store');
    }
}
