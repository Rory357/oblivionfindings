<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientDailyNoteResource;
use App\Models\Client;
use App\Models\ClientNote;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientDailyNoteController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('progress_notes.viewAny'), 403);

        $notes = $this->baseQuery($request, $client)
            ->dailyNotes()
            ->when($request->boolean('flagged'), fn ($q) => $q->where('is_flagged', true))
            ->when($request->boolean('reviewed') === false && $request->has('reviewed'), fn ($q) => $q->whereNull('reviewed_at'))
            ->when($request->boolean('mine'), fn ($q) => $q->where('user_id', $request->user()->id))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return ClientDailyNoteResource::collection($notes);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $this->authorize('create', ClientNote::class);

        $data = $this->validatedPayload($request, $client, creating: true);
        $isDraft = (bool) ($data['is_draft'] ?? false);

        ClientNote::query()->create([
            ...$data,
            'client_id' => $client->id,
            'organization_id' => $request->user()?->organization_id ?? $client->organization_id,
            'user_id' => $request->user()->id,
            'type' => $data['type'] ?? 'daily_note',
            'category' => $data['category'] ?? 'other',
            'occurred_at' => WorkerClock::toUtc($data['occurred_at'] ?? null) ?? now(),
            'follow_up_due_at' => WorkerClock::toUtc($data['follow_up_due_at'] ?? null),
            'visibility' => $isDraft ? 'internal' : ($data['visibility'] ?? 'internal'),
            'appears_on_timeline' => (bool) ($data['appears_on_timeline'] ?? true),
            'is_draft' => $isDraft,
        ]);

        return back()->with('success', $isDraft ? 'Daily note draft saved.' : 'Daily note added.');
    }

    public function update(Request $request, Client $client, ClientNote $note)
    {
        $this->authorize('view', $client);
        abort_unless($note->client_id === $client->id, 404);
        $this->ensureWorkspaceNote($note);
        $this->authorize('update', $note);

        $data = $this->validatedPayload($request, $client, creating: false);
        if (! $note->is_draft && ($data['is_draft'] ?? false) === true) {
            throw ValidationException::withMessages([
                'is_draft' => 'A submitted daily note cannot be moved back to draft.',
            ]);
        }
        if (($data['is_draft'] ?? $note->is_draft) === true) {
            $data['visibility'] = 'internal';
        }
        foreach (['occurred_at', 'follow_up_due_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = WorkerClock::toUtc($data[$field]);
            }
        }

        $note->update($data);

        return back()->with('success', 'Daily note updated.');
    }

    public function destroy(Request $request, Client $client, ClientNote $note)
    {
        $this->authorize('view', $client);
        abort_unless($note->client_id === $client->id, 404);
        $this->ensureWorkspaceNote($note);
        $this->authorize('delete', $note);

        $note->delete();

        return back()->with('success', 'Daily note deleted.');
    }

    public function flag(Request $request, Client $client, ClientNote $note)
    {
        $this->authorize('view', $client);
        abort_unless($note->client_id === $client->id, 404);
        $this->ensureWorkspaceNote($note);
        $this->authorize('flag', $note);

        $data = $request->validate([
            'is_flagged' => ['required', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $note->update([
            'is_flagged' => $data['is_flagged'],
            'flagged_reason' => $data['is_flagged'] ? ($data['flagged_reason'] ?? $note->flagged_reason) : null,
            'reviewed_at' => $data['is_flagged'] ? null : $note->reviewed_at,
            'reviewed_by' => $data['is_flagged'] ? null : $note->reviewed_by,
        ]);

        return back()->with('success', $note->is_flagged ? 'Note flagged for review.' : 'Note flag cleared.');
    }

    public function reviewQueue(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        abort_unless($request->user()?->canDo('progress_notes.review'), 403);

        return ClientDailyNoteResource::collection(
            $this->baseQuery($request, $client)
                ->reviewQueue()
                ->orderByDesc('created_at')
                ->limit(25)
                ->get(),
        );
    }

    public function review(Request $request, Client $client, ClientNote $note)
    {
        $this->authorize('view', $client);
        abort_unless($note->client_id === $client->id, 404);
        $this->ensureWorkspaceNote($note);
        $this->authorize('review', $note);

        $note->update([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Daily note marked reviewed.');
    }

    private function baseQuery(Request $request, Client $client)
    {
        return ClientNote::query()
            ->where('client_id', $client->id)
            ->forUser($request->user())
            ->with(['author:id,name', 'reviewer:id,name', 'shift:id,starts_at,ends_at']);
    }

    private function ensureWorkspaceNote(ClientNote $note): void
    {
        abort_unless(in_array($note->type, [
            'daily_note',
            'quick',
            'communication',
            'note',
            'progress_note',
            'handover',
        ], true), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(
        Request $request,
        Client $client,
        bool $creating,
    ): array {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'type' => [$creating ? 'nullable' : 'sometimes', 'string', Rule::in(['daily_note', 'quick', 'communication', 'note', 'progress_note', 'handover'])],
            'category' => ['nullable', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:255'],
            'goal' => ['nullable', 'string', 'max:255'],
            'body' => [$required, 'string', 'min:2'],
            'occurred_at' => ['nullable', 'date'],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')
                    ->where(fn ($query) => $query->where('client_id', $client->id)),
            ],
            'visibility' => ['nullable', 'string', Rule::in(['internal', 'portal'])],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
            'attachments' => ['nullable', 'array'],
            'mood_rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'behaviour_tags' => ['nullable', 'array'],
            'behaviour_tags.*' => ['string', 'max:80'],
            'concerns_flags' => ['nullable', 'array'],
            'concerns_flags.*' => ['string', 'max:80'],
            'follow_up_action' => ['nullable', 'string', 'max:255'],
            'follow_up_due_at' => ['nullable', 'date'],
            'follow_up_completed_at' => ['nullable', 'date'],
            'appears_on_timeline' => ['nullable', 'boolean'],
            'is_draft' => ['nullable', 'boolean'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_relationship' => ['nullable', 'string', 'max:120'],
            'contact_method' => ['nullable', 'string', 'max:80'],
        ]);
    }
}
