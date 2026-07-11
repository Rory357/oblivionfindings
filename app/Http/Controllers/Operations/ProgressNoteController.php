<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Concerns\HandlesOfflineSubmission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgressNoteController extends Controller
{
    use HandlesOfflineSubmission;

    /*
     * The standalone progress-notes index page was retired in the client
     * profile redesign — progress notes live on each client profile's Daily
     * Notes tab (type filter). These endpoints are compatibility adapters:
     * all new writes use ClientNote, while migrated legacy IDs remain usable
     * for old links and integrations.
     */

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.create'), 403);

        $clientId = $request->integer('client_id');
        $organizationId = $auth->organization_id;
        $data = $request->validate([
            'client_id' => [
                'required',
                'integer',
                Rule::exists('clients', 'id')->where(
                    fn ($query) => $query->when(
                        $organizationId,
                        fn ($scoped) => $scoped->where('organization_id', $organizationId),
                    ),
                ),
            ],
            'content' => ['required', 'string'],
            'note_type' => ['required', 'string', 'max:100'],
            'shift_id' => [
                'nullable',
                'integer',
                Rule::exists('shifts', 'id')->where(
                    fn ($query) => $query
                        ->where('client_id', $clientId)
                        ->when(
                            $organizationId,
                            fn ($scoped) => $scoped->where('organization_id', $organizationId),
                        ),
                ),
            ],
            'care_plan_goal_id' => [
                'nullable',
                'integer',
                Rule::exists('care_plan_goals', 'id')->where(
                    fn ($query) => $query
                        ->where('client_id', $clientId)
                        ->when(
                            $organizationId,
                            fn ($scoped) => $scoped->where('organization_id', $organizationId),
                        ),
                ),
            ],
            'mood_rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'emotions' => ['nullable', 'array'],
            'emotions.*' => ['string', 'in:happy,calm,anxious,sad,frustrated,excited,tired,confused'],
            'visibility' => ['nullable', 'string', 'in:staff_only,include_family,private'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
            ...$this->offlineSubmissionRules(),
        ]);

        $client = Client::query()->findOrFail($data['client_id']);
        $this->authorize('view', $client);

        return $this->runOfflineSubmissionOnce('progress_note', $data, function () use ($auth, $data) {
            return $this->createProgressNote($auth, $data);
        });
    }

    private function createProgressNote(User $auth, array $data)
    {
        $visibility = match ($data['visibility'] ?? 'staff_only') {
            'include_family' => 'portal',
            default => 'internal',
        };

        ClientNote::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'body' => $data['content'],
            'type' => 'progress_note',
            'category' => $data['note_type'],
            'shift_id' => $data['shift_id'] ?? null,
            'care_plan_goal_id' => $data['care_plan_goal_id'] ?? null,
            'mood_rating' => $data['mood_rating'] ?? null,
            'behaviour_tags' => $data['emotions'] ?? null,
            'visibility' => $visibility,
            'is_private' => ($data['visibility'] ?? null) === 'private',
            'is_flagged' => $data['is_flagged'] ?? false,
            'flagged_reason' => $data['flagged_reason'] ?? null,
            'user_id' => $auth->id,
            'occurred_at' => now(),
            'appears_on_timeline' => true,
            'is_draft' => false,
        ]);

        return redirect()->back()->with('success', 'Progress note created.');
    }

    public function update(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.update'), 403);

        $note = $this->findCanonicalNote($auth, $note);
        $this->authorize('view', $note->client);

        $data = $request->validate([
            'content' => ['sometimes', 'required', 'string'],
            'note_type' => ['sometimes', 'required', 'string', 'max:100'],
            'mood_rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'visibility' => ['nullable', 'string', 'in:staff_only,include_family,private'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $updates = [];
        if (array_key_exists('content', $data)) {
            $updates['body'] = $data['content'];
        }
        if (array_key_exists('note_type', $data)) {
            $updates['category'] = $data['note_type'];
        }
        foreach (['mood_rating', 'is_flagged', 'flagged_reason'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (array_key_exists('visibility', $data)) {
            $updates['visibility'] = $data['visibility'] === 'include_family'
                ? 'portal'
                : 'internal';
            $updates['is_private'] = $data['visibility'] === 'private';
        }
        $updates['edited_at'] = now();
        $updates['edited_by'] = $auth->id;

        $note->update($updates);

        return redirect()->back()->with('success', 'Progress note updated.');
    }

    public function destroy(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.delete'), 403);

        $note = $this->findCanonicalNote($auth, $note);
        $this->authorize('view', $note->client);

        $note->delete();

        return redirect()->back()->with('success', 'Progress note deleted.');
    }

    private function findCanonicalNote(User $auth, int|string $note): ClientNote
    {
        $scoped = fn () => ClientNote::query()
            ->where('type', 'progress_note')
            ->when(
                $auth->organization_id,
                fn ($query) => $query->where('organization_id', $auth->organization_id),
            );

        $migratedLegacy = $scoped()
            ->where('legacy_progress_note_id', $note)
            ->first();

        return $migratedLegacy ?? $scoped()->findOrFail($note);
    }
}
