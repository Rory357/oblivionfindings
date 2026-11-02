<?php

namespace App\Http\Controllers;

use App\Models\ClientIncident;
use App\Models\ClientIncidentNote;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Answer 20 (Stephan, 1 Nov 2026): a submitted incident report is never edited. A manager who may open the incident
 * and review incidents (incidents.approve, route middleware) adds a dated note beside the reporter's words, and the
 * reporter is told. Notes are kept as written.
 */
class IncidentNoteController extends Controller
{
    public function store(Request $request, ClientIncident $incident): RedirectResponse
    {
        $this->authorize('view', $incident);
        $data = $request->validate([
            'field' => ['required', Rule::in(ClientIncidentNote::FIELDS)],
            'note' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $actor = $request->user();

        DB::transaction(function () use ($incident, $data, $actor): void {
            $locked = ClientIncident::query()->whereKey($incident->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [null, '', 'draft'], true)) {
                throw ValidationException::withMessages(['note' => 'A draft can still be changed by its reporter, so it takes no notes.']);
            }
            $note = ClientIncidentNote::create([
                'client_incident_id' => $locked->id,
                'field' => $data['field'],
                'note' => trim((string) $data['note']),
                'author_id' => $actor->id,
                'author_name' => (string) $actor->name,
            ]);
            AuditLogger::logOrFail('incident.noteAdded', $locked, [
                'actor_id' => $actor->id, 'note_id' => $note->id, 'field' => $data['field'],
            ]);
            $reporter = $locked->reported_by ? User::query()->find($locked->reported_by) : null;
            if ($reporter !== null && (int) $reporter->id !== (int) $actor->id) {
                $reporter->notify(new AppEventNotification([
                    'title' => 'A note was added to your report',
                    'message' => $actor->name.' added a note beside your words in '.$locked->reference_number.'.',
                    'type' => 'incident_note_added',
                    'client_incident_id' => $locked->id,
                    'note_id' => $note->id,
                    'url' => '/incidents/'.$locked->id,
                ]));
            }
        }, 3);

        return back()->with('success', 'Note added.');
    }
}
