<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientNoteController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Can only add notes if you can view the client
        $this->authorize('view', $client);
        abort_unless($user->canDo('timeline.create'), 403);

        $data = $request->validate([
            'type' => ['nullable', 'string', 'in:note,shift_note,progress_note,handover'],
            'subject' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'min:2'],
            'occurred_at' => ['nullable', 'date'],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')
                    ->where(fn ($query) => $query->where('client_id', $client->id)),
            ],
            'visibility' => ['nullable', 'string', 'in:internal,portal'],
            'pin' => ['nullable', 'boolean'],
        ]);

        $type = $data['type'] ?? 'note';
        $occurredAt = isset($data['occurred_at']) ? now()->parse($data['occurred_at']) : now();
        $visibility = $data['visibility'] ?? 'internal';

        $note = ClientNote::create([
            'client_id' => $client->id,
            'shift_id' => $data['shift_id'] ?? null,
            'user_id' => $user->id,
            'type' => $type,
            'subject' => $data['subject'] ?? null,
            'goal' => $data['goal'] ?? null,
            'body' => $data['body'],
            'occurred_at' => $occurredAt,
            'visibility' => $visibility,
            'is_pinned' => (bool) ($data['pin'] ?? false) && $type === 'handover',
        ]);

        return back()->with('status', 'Note added.');
    }

    public function togglePin(Request $request, Client $client, ClientNote $note)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $this->authorize('view', $client);
        abort_unless($user->canDo('timeline.pin') || $user->canDo('clients.update'), 403);

        abort_unless($note->client_id === $client->id, 404);

        $note->update([
            'is_pinned' => ! $note->is_pinned,
        ]);

        // Keep timeline event in sync
        TimelineEvent::query()
            ->where('source_type', ClientNote::class)
            ->where('source_id', $note->id)
            ->update(['is_pinned' => $note->is_pinned]);

        return back()->with('status', $note->is_pinned ? 'Pinned to handover.' : 'Unpinned.');
    }
}
