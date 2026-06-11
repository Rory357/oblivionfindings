<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Concerns\HandlesOfflineSubmission;
use App\Http\Controllers\Controller;
use App\Models\ProgressNote;
use Illuminate\Http\Request;

class ProgressNoteController extends Controller
{
    use HandlesOfflineSubmission;

    /*
     * The standalone progress-notes index page was retired in the client
     * profile redesign — progress notes live on each client profile's Daily
     * Notes tab (type filter). The old route now redirects (see
     * routes/operations.php); store/update/destroy below remain in use
     * (care-plan goal quick notes).
     */

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'content' => ['required', 'string'],
            'note_type' => ['required', 'string', 'max:100'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'care_plan_goal_id' => ['nullable', 'integer', 'exists:care_plan_goals,id'],
            'mood_rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'emotions' => ['nullable', 'array'],
            'emotions.*' => ['string', 'in:happy,calm,anxious,sad,frustrated,excited,tired,confused'],
            'visibility' => ['nullable', 'string', 'in:staff_only,include_family,private'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
            ...$this->offlineSubmissionRules(),
        ]);

        return $this->runOfflineSubmissionOnce('progress_note', $data, function () use ($auth, $data) {
            return $this->createProgressNote($auth, $data);
        });
    }

    private function createProgressNote(User $auth, array $data)
    {
        $note = ProgressNote::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'content' => $data['content'],
            'note_type' => $data['note_type'],
            'shift_id' => $data['shift_id'] ?? null,
            'care_plan_goal_id' => $data['care_plan_goal_id'] ?? null,
            'mood_rating' => $data['mood_rating'] ?? null,
            'emotions' => $data['emotions'] ?? null,
            'visibility' => $data['visibility'] ?? 'staff_only',
            'is_flagged' => $data['is_flagged'] ?? false,
            'flagged_reason' => $data['flagged_reason'] ?? null,
            'author_id' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Progress note created.');
    }

    public function update(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.update'), 403);

        $note = ProgressNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $data = $request->validate([
            'content' => ['sometimes', 'required', 'string'],
            'note_type' => ['sometimes', 'required', 'string', 'max:100'],
            'mood_rating' => ['nullable', 'integer', 'min:1', 'max:10'],
            'visibility' => ['nullable', 'string', 'in:staff_only,include_family,private'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $note->update($data);

        return redirect()->back()->with('success', 'Progress note updated.');
    }

    public function destroy(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.delete'), 403);

        $note = ProgressNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $note->delete();

        return redirect()->back()->with('success', 'Progress note deleted.');
    }
}
