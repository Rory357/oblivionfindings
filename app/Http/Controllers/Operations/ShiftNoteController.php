<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ShiftNote;
use Illuminate\Http\Request;

class ShiftNoteController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $filters = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'note_type' => ['nullable', 'string', 'in:general,handover,incident,medical,behavioural'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $notes = ShiftNote::query()
            ->where('organization_id', $auth->organization_id)
            ->with(['shift.client:id,first_name,last_name', 'author:id,name'])
            ->when(!empty($filters['shift_id']), fn ($q) => $q->where('shift_id', $filters['shift_id']))
            ->when(!empty($filters['note_type']), fn ($q) => $q->where('note_type', $filters['note_type']))
            ->when(!empty($filters['author_id']), fn ($q) => $q->where('author_id', $filters['author_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('operations/shift-notes/Index', [
            'notes' => $notes,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.edit'), 403);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'note_type' => ['nullable', 'string', 'in:general,handover,incident,medical,behavioural'],
            'is_private' => ['nullable', 'boolean'],
        ]);

        ShiftNote::create([
            'organization_id' => $auth->organization_id,
            'shift_id' => $shift,
            'author_id' => $auth->id,
            'content' => $data['content'],
            'note_type' => $data['note_type'] ?? 'general',
            'is_private' => $data['is_private'] ?? false,
        ]);

        return redirect()->back();
    }

    public function update(Request $request, $shift, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.edit'), 403);

        $shiftNote = ShiftNote::where('organization_id', $auth->organization_id)->findOrFail($note);

        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'note_type' => ['nullable', 'string', 'in:general,handover,incident,medical,behavioural'],
        ]);

        $shiftNote->update([
            'content' => $data['content'],
            'note_type' => $data['note_type'] ?? $shiftNote->note_type,
        ]);

        return redirect()->back();
    }

    public function destroy(Request $request, $shift, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.delete'), 403);

        $shiftNote = ShiftNote::where('organization_id', $auth->organization_id)->findOrFail($note);
        $shiftNote->delete();

        return redirect()->back();
    }
}
