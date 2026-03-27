<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ProgressNote;
use App\Models\User;
use Illuminate\Http\Request;

class ProgressNoteController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('progress_notes.viewAny'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'note_type' => ['nullable', 'string'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'flagged' => ['nullable', 'boolean'],
        ]);

        $notes = ProgressNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'client:id,first_name,last_name',
                'author:id,name',
                'shift:id,starts_at,ends_at',
                'goal:id,title,care_plan_id',
            ])
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(!empty($data['note_type']), fn ($q) => $q->where('note_type', $data['note_type']))
            ->when(!empty($data['author_id']), fn ($q) => $q->where('author_id', $data['author_id']))
            ->when(!empty($data['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $data['date_from']))
            ->when(!empty($data['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $data['date_to']))
            ->when(isset($data['flagged']) && $data['flagged'], fn ($q) => $q->flagged())
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $authors = User::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $statsBase = ProgressNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id));

        $stats = [
            'total' => (clone $statsBase)->count(),
            'this_week' => (clone $statsBase)->where('created_at', '>=', now()->startOfWeek())->count(),
            'flagged' => (clone $statsBase)->where('is_flagged', true)->count(),
        ];

        return inertia('operations/progress-notes/Index', [
            'notes' => $notes,
            'clients' => $clients,
            'authors' => $authors,
            'stats' => $stats,
            'filters' => $request->only(['client_id', 'note_type', 'author_id', 'date_from', 'date_to', 'flagged']),
        ]);
    }

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
            'visibility' => ['nullable', 'string', 'in:staff_only,include_family,private'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        ProgressNote::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'content' => $data['content'],
            'note_type' => $data['note_type'],
            'shift_id' => $data['shift_id'] ?? null,
            'care_plan_goal_id' => $data['care_plan_goal_id'] ?? null,
            'mood_rating' => $data['mood_rating'] ?? null,
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
        abort_unless($auth && $auth->canDo('progress_notes.edit'), 403);

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
