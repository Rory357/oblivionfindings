<?php

namespace App\Http\Controllers;

use App\Models\PersonalCalendarEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PersonalCalendarEntryController extends Controller
{
    public function show(Request $request, int $entry)
    {
        $this->authorizePersonal($request);

        return response()->json(['entry' => PersonalCalendarEntry::where('user_id', $request->user()->id)->findOrFail($entry)->payload()]);
    }

    public function store(Request $request)
    {
        $this->authorizePersonal($request);
        $request->validate(['request_id' => ['required', 'uuid']]);
        $data = $this->validated($request);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        // Persist the request identity before a retry can create another copy.
        $entry = PersonalCalendarEntry::withTrashed()->firstOrCreate([
            'user_id' => $request->user()->id, 'request_id' => $request->input('request_id'),
        ], [...$data, 'request_hash' => $hash]);
        abort_if($entry->trashed() || $entry->request_hash !== $hash, 409, 'This save request was already used. Reopen New entry to create another item.');

        $created = $entry->wasRecentlyCreated;

        return response()->json(['entry' => $entry->refresh()->payload()], $created ? 201 : 200);
    }

    public function update(Request $request, int $entry)
    {
        return $this->mutate($request, $entry, function (PersonalCalendarEntry $record) use ($request): void {
            $record->fill($this->validated($request, $record));
            $record->version++;
            $record->save();
        });
    }

    public function destroy(Request $request, int $entry)
    {
        return $this->mutate($request, $entry, function (PersonalCalendarEntry $record): void {
            $record->version++;
            $record->save();
            $record->delete();
        });
    }

    public function restore(Request $request, int $entry)
    {
        return $this->mutate($request, $entry, function (PersonalCalendarEntry $record): void {
            abort_unless($record->trashed(), 409, 'This entry has already been restored.');
            $record->version++;
            $record->restore();
        }, true);
    }

    private function mutate(Request $request, int $entry, \Closure $change, bool $withTrashed = false)
    {
        $this->authorizePersonal($request);
        $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($request, $entry, $change, $withTrashed) {
            $query = PersonalCalendarEntry::where('user_id', $request->user()->id);
            if ($withTrashed) {
                $query->withTrashed();
            }
            $record = $query->lockForUpdate()->findOrFail($entry);
            abort_unless($record->version === $request->integer('version'), 409, 'This entry changed elsewhere. Your changes have not been saved. Reopen it to load the latest version.');
            $change($record);

            return response()->json(['entry' => $record->payload()]);
        });
    }

    private function authorizePersonal(Request $request): void
    {
        abort_unless($request->user() && PersonalCalendarEntry::canUse($request->user()), 403);
    }

    private function validated(Request $request, ?PersonalCalendarEntry $entry = null): array
    {
        $required = $entry ? 'sometimes' : 'required';
        $data = $request->validate([
            'kind' => [$required, 'required', 'in:task,meeting,appointment,reminder'],
            'title' => [$required, 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Require an explicit offset: local browser/server timezone differences cannot shift a save.
            'start_at' => [$required, 'required', 'date', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/'],
            'end_at' => ['sometimes', 'nullable', 'date', 'regex:/(?:Z|[+-]\d{2}:\d{2})$/'],
            'all_day' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:scheduled,completed,cancelled'],
        ]);
        if (isset($data['title'])) {
            $data['title'] = trim($data['title']);
            if ($data['title'] === '') {
                throw ValidationException::withMessages(['title' => 'Enter a title.']);
            }
        }
        $start = isset($data['start_at']) ? Carbon::parse($data['start_at'])->utc() : $entry?->start_at;
        $end = array_key_exists('end_at', $data)
            ? ($data['end_at'] ? Carbon::parse($data['end_at'])->utc() : null)
            : $entry?->end_at;
        if ($end && $start && $end->lte($start)) {
            throw ValidationException::withMessages(['end_at' => 'End must be after the start.']);
        }
        if (isset($data['start_at'])) {
            $data['start_at'] = $start->format('Y-m-d H:i:s');
        }
        if (array_key_exists('end_at', $data)) {
            $data['end_at'] = $end?->format('Y-m-d H:i:s');
        }

        return $data;
    }
}
