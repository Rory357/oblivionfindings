<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyNote;
use App\Models\TimelineEvent;
use Illuminate\Http\Request;

class PortalFamilyNoteController extends Controller
{
    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $notes = FamilyNote::forClient($client->id)
            ->with(['creator:id,name', 'completer:id,name', 'staffResponder:id,name', 'shift:id,starts_at'])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'cancelled')")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->title,
                'description' => $n->description,
                'note_type' => $n->note_type,
                'priority' => $n->priority,
                'status' => $n->status,
                'due_date' => $n->due_date?->toDateString(),
                'due_time' => $n->due_time,
                'completed_at' => $n->completed_at?->toISOString(),
                'completed_by_name' => $n->completer?->name,
                'staff_response' => $n->staff_response,
                'staff_responded_by_name' => $n->staffResponder?->name,
                'staff_responded_at' => $n->staff_responded_at?->toISOString(),
                'assigned_shift_date' => $n->shift?->starts_at?->format('j M'),
                'creator_name' => $n->creator?->name,
                'created_by' => $n->created_by,
                'created_at' => $n->created_at?->toISOString(),
                'is_overdue' => $n->due_date && $n->due_date->isPast() && in_array($n->status, ['open', 'in_progress']),
            ]);

        $stats = [
            'open' => FamilyNote::forClient($client->id)->open()->count(),
            'completed' => FamilyNote::forClient($client->id)->where('status', 'completed')->count(),
            'overdue' => FamilyNote::forClient($client->id)->overdue()->count(),
        ];

        return inertia('portal/family-notes', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
            ],
            'notes' => $notes,
            'stats' => $stats,
        ]);
    }

    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'note_type' => 'required|string|in:note,todo,request,reminder',
            'priority' => 'required|string|in:low,normal,high,urgent',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
        ]);

        $note = FamilyNote::create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            ...$data,
            'visibility' => 'portal',
        ]);

        TimelineEvent::create([
            'source_type' => FamilyNote::class,
            'source_id' => $note->id,
            'occurred_at' => now(),
            'type' => 'family_note_created',
            'actor_user_id' => $user->id,
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'subject' => 'Family note: ' . $data['title'],
            'body' => $data['description'],
            'meta' => array_filter([
                'note_type' => $data['note_type'],
                'priority' => $data['priority'],
                'due_date' => $data['due_date'] ?? null,
            ]),
            'visibility' => 'portal',
            'is_pinned' => false,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Note created.');
    }

    public function update(Request $request, Client $client, FamilyNote $familyNote)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless($familyNote->created_by === $user->id, 403);
        abort_unless(in_array($familyNote->status, ['open', 'in_progress']), 422);

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'note_type' => 'sometimes|string|in:note,todo,request,reminder',
            'priority' => 'sometimes|string|in:low,normal,high,urgent',
            'due_date' => 'nullable|date',
            'due_time' => 'nullable|date_format:H:i',
        ]);

        $familyNote->update($data);

        return redirect()->back()->with('success', 'Note updated.');
    }

    public function destroy(Request $request, Client $client, FamilyNote $familyNote)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless($familyNote->created_by === $user->id, 403);

        $familyNote->delete();

        return redirect()->back()->with('success', 'Note removed.');
    }
}
