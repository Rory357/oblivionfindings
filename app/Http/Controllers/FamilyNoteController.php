<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\FamilyNote;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FamilyNoteController extends Controller
{
    public function respond(Request $request, Client $client, FamilyNote $familyNote)
    {
        $this->authorize('view', $client);
        abort_unless($familyNote->client_id === $client->id, 404);

        $data = $request->validate([
            'staff_response' => 'required|string|max:2000',
        ]);

        $familyNote->update([
            'staff_response' => $data['staff_response'],
            'staff_responded_by' => $request->user()->id,
            'staff_responded_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Response added.');
    }

    public function updateStatus(Request $request, Client $client, FamilyNote $familyNote)
    {
        $this->authorize('view', $client);
        abort_unless($familyNote->client_id === $client->id, 404);

        $data = $request->validate([
            'status' => 'required|string|in:open,in_progress,completed,cancelled',
        ]);

        $updates = ['status' => $data['status']];

        if ($data['status'] === 'completed') {
            $updates['completed_at'] = now();
            $updates['completed_by'] = $request->user()->id;

            app(\App\Services\Timeline\TimelineEmitter::class)->record([
                'source_type' => FamilyNote::class,
                'source_id' => $familyNote->id,
                'occurred_at' => now(),
                'type' => 'family_note_completed',
                'actor_user_id' => $request->user()->id,
                'client_id' => $client->id,
                'site_id' => $client->site_id,
                'subject' => 'Family note completed: ' . $familyNote->title,
                'body' => null,
                'visibility' => 'portal',
                'is_pinned' => false,
                'created_by' => $request->user()->id,
            ]);
        }

        $familyNote->update($updates);

        return redirect()->back()->with('success', 'Status updated.');
    }

    public function assignToShift(Request $request, Client $client, FamilyNote $familyNote)
    {
        $this->authorize('view', $client);
        abort_unless($familyNote->client_id === $client->id, 404);

        $data = $request->validate([
            'shift_id' => 'required|integer|exists:shifts,id',
        ]);

        $shift = Shift::query()->findOrFail($data['shift_id']);
        abort_unless($shift->client_id === $client->id, 422);

        if (in_array($shift->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'shift_id' => 'Family notes can only be assigned to active or upcoming shifts.',
            ]);
        }

        $taskLabel = 'Family note: ' . $familyNote->title;

        if (! ShiftTask::where('shift_id', $shift->id)->where('label', $taskLabel)->exists()) {
            ShiftTask::create([
                'shift_id' => $shift->id,
                'label' => $taskLabel,
                'is_completed' => false,
                'sort_order' => ((int) ShiftTask::where('shift_id', $shift->id)->max('sort_order')) + 1,
            ]);
        }

        $familyNote->update([
            'assigned_to_shift_id' => $shift->id,
            'status' => $familyNote->status === 'open' ? 'in_progress' : $familyNote->status,
        ]);

        app(\App\Services\Timeline\TimelineEmitter::class)->record([
            'source_type' => FamilyNote::class,
            'source_id' => $familyNote->id,
            'occurred_at' => now(),
            'type' => 'family_note_assigned_to_shift',
            'actor_user_id' => $request->user()->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Family note assigned to shift: ' . $familyNote->title,
            'body' => null,
            'meta' => [
                'shift_id' => $shift->id,
                'shift_starts_at' => optional($shift->starts_at)->toIso8601String(),
                'shift_status' => $shift->status,
            ],
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->back()->with('success', 'Added to shift checklist.');
    }
}
