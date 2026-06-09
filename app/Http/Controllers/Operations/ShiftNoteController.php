<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ShiftNoteController extends Controller
{
    /**
     * Author edit window: a support worker may edit their own note for this many
     * days after it was written; after that only a manager can. Shared with the
     * frontend permission affordances (see note-detail-dialog.tsx).
     */
    public const EDIT_WINDOW_DAYS = 7;

    /** Note types surfaced in the redesigned workspace. */
    public const TYPES = ['shift_note', 'progress_note', 'handover', 'incident', 'note'];

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $filters = $request->validate([
            'week' => ['nullable', 'date'],
        ]);

        // Week (Mon–Sun) is the unit of navigation, mirroring rostering/handovers.
        // Compute the window in the worker timezone, then query the UTC-stored
        // created_at column with UTC-converted bounds (reference_eloquent_timezone_storage).
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $weekStart = ! empty($filters['week'])
            ? Carbon::parse($filters['week'], $tz)->startOfWeek(Carbon::MONDAY)
            : Carbon::now($tz)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
        $startUtc = $weekStart->copy()->utc();
        $endUtc = $weekEnd->copy()->utc();

        $orgId = $auth->organization_id;

        $notes = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            // Scope the week by the note's effective date — the documented
            // shift's start (what the UI groups + navigates by), falling back to
            // created_at only when the shift has no start. Keeps the week filter
            // consistent with the day grouping, the coverage gaps and the
            // post-create "View in week" jump.
            ->where(function ($scope) use ($startUtc, $endUtc) {
                $scope
                    ->whereHas('shift', fn ($s) => $s
                        ->whereNotNull('starts_at')
                        ->whereBetween('starts_at', [$startUtc, $endUtc]))
                    ->orWhere(fn ($noShift) => $noShift
                        ->whereDoesntHave('shift', fn ($s) => $s->whereNotNull('starts_at'))
                        ->whereBetween('created_at', [$startUtc, $endUtc]));
            })
            ->with([
                'user:id,name',
                'client:id,first_name,last_name,site_id',
                'client.site:id,name',
                'shift:id,starts_at,ends_at,shift_type,client_id,user_id',
                'reviewer:id,name',
                'editor:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(400)
            ->get()
            ->map(fn (ClientNote $note) => $this->mapNote($note, $auth))
            ->values();

        return inertia('operations/shift-notes/Index', [
            'notes' => $notes,
            'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $weekEnd->toDateString(),
            'filters' => ['week' => $weekStart->toDateString()],
            'catalogue' => $this->catalogue($auth),
            'can' => [
                'create' => (bool) $auth->canDo('shifts.viewAny'),
                'manage' => (bool) $auth->canDo('shifts.manageAny'),
            ],
            'currentUser' => [
                'id' => $auth->id,
                'name' => $auth->name,
                'is_manager' => (bool) $auth->canDo('shifts.manageAny'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $validated = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'body' => ['required', 'string', 'max:5000'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
            'is_private' => ['nullable', 'boolean'],
        ]);

        $shift = Shift::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($validated['shift_id']);

        $flagged = (bool) ($validated['is_flagged'] ?? false);

        ClientNote::query()->create([
            'organization_id' => $auth->organization_id,
            'client_id' => $shift->client_id,
            'shift_id' => $shift->id,
            'user_id' => $auth->id,
            'type' => $validated['type'],
            'body' => $validated['body'],
            'occurred_at' => $shift->starts_at ?? now(),
            'visibility' => 'internal',
            'is_flagged' => $flagged,
            'flagged_reason' => $flagged ? ($validated['flagged_reason'] ?? null) : null,
            'is_private' => (bool) ($validated['is_private'] ?? false),
            'appears_on_timeline' => true,
        ]);

        return redirect()->back()->with('success', 'Shift note added.');
    }

    public function update(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $note = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $permission = $this->editPermission($note, $auth);
        abort_unless($permission['editable'], 403, $permission['reason'] === 'window_closed'
            ? 'The edit window for this note has closed — only a manager can edit it now.'
            : 'You are not authorized to edit this note.');

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'body' => ['required', 'string', 'max:5000'],
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
            'is_private' => ['nullable', 'boolean'],
        ]);

        $flagged = (bool) ($validated['is_flagged'] ?? false);

        $note->update([
            'type' => $validated['type'],
            'body' => $validated['body'],
            'is_flagged' => $flagged,
            'flagged_reason' => $flagged ? ($validated['flagged_reason'] ?? null) : null,
            'is_private' => (bool) ($validated['is_private'] ?? false),
            'edited_at' => now(),
            'edited_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Shift note updated.');
    }

    public function export(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $orgId = $auth->organization_id;
        $filters = $request->only(['q', 'type', 'client_id', 'author_id', 'date_from', 'date_to', 'flagged', 'week']);

        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');

        $notes = ClientNote::query()
            ->whereNotNull('shift_id')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where('body', 'like', "%{$search}%"))
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($filters['author_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['date_from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->when($filters['week'] ?? null, function ($q, $week) use ($tz) {
                $start = Carbon::parse($week, $tz)->startOfWeek(Carbon::MONDAY)->utc();
                $end = Carbon::parse($week, $tz)->endOfWeek(Carbon::SUNDAY)->utc();

                return $q->whereBetween('created_at', [$start, $end]);
            })
            ->when(isset($filters['flagged']) && $filters['flagged'], fn ($q) => $q->where('is_flagged', true))
            ->orderByDesc('created_at')
            ->get();

        $csv = "Date,Client,Author,Type,Content,Shift Start,Shift End,Flagged,Private\n";
        foreach ($notes as $note) {
            $client = $note->client ? "{$note->client->first_name} {$note->client->last_name}" : '';
            $author = $note->user->name ?? '';
            $shiftStart = $note->shift?->starts_at?->format('Y-m-d H:i') ?? '';
            $shiftEnd = $note->shift?->ends_at?->format('Y-m-d H:i') ?? '';
            $content = str_replace('"', '""', $note->body ?? '');
            $csv .= sprintf(
                "%s,\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",\"%s\",%s,%s\n",
                $note->created_at->format('Y-m-d H:i'),
                $client,
                $author,
                $note->type ?? 'note',
                $content,
                $shiftStart,
                $shiftEnd,
                $note->is_flagged ? 'Yes' : 'No',
                $note->is_private ? 'Yes' : 'No'
            );
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="shift-notes-export-'.now()->format('Y-m-d').'.csv"',
        ]);
    }

    public function flag(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $note = ClientNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $data = $request->validate([
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $note->update([
            'is_flagged' => ! $note->is_flagged,
            'flagged_reason' => $note->is_flagged ? null : ($data['flagged_reason'] ?? 'Flagged for review'),
        ]);

        return redirect()->back()->with('success', $note->is_flagged ? 'Note flagged.' : 'Flag removed.');
    }

    public function markReviewed(Request $request, $note)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $note = ClientNote::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($note);

        $note->update([
            'reviewed_at' => now(),
            'reviewed_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Note marked as reviewed.');
    }

    /**
     * Shape a single note for the redesigned index — the record plus the
     * per-user edit-lock flags the detail popup gates its affordances on.
     *
     * @return array<string, mixed>
     */
    protected function mapNote(ClientNote $note, User $auth): array
    {
        $client = $note->client;
        $site = $client?->site;
        $permission = $this->editPermission($note, $auth);

        return [
            'id' => $note->id,
            'type' => $note->type,
            'body' => $note->body,
            'subject' => $note->subject,
            'is_flagged' => (bool) $note->is_flagged,
            'flagged_reason' => $note->flagged_reason,
            'is_private' => (bool) $note->is_private,
            'reviewed_at' => optional($note->reviewed_at)->toISOString(),
            'reviewer' => $note->reviewer ? ['id' => $note->reviewer->id, 'name' => $note->reviewer->name] : null,
            'edited_at' => optional($note->edited_at)->toISOString(),
            'editor' => $note->editor ? ['id' => $note->editor->id, 'name' => $note->editor->name] : null,
            'created_at' => optional($note->created_at)->toISOString(),
            'user' => $note->user ? ['id' => $note->user->id, 'name' => $note->user->name] : null,
            'client' => $client ? [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'site_id' => $client->site_id,
            ] : null,
            'site' => $site ? ['id' => $site->id, 'name' => $site->name] : null,
            'shift' => $this->shiftPayload($note->shift),
            'can_edit' => $permission['editable'],
            'lock' => [
                'locked' => $permission['locked'],
                'reason' => $permission['reason'],
                'days_left' => $permission['days_left'],
                'age_days' => $permission['age_days'],
            ],
        ];
    }

    /**
     * Editability of a note for a given user. Managers can always edit; the
     * author can edit for EDIT_WINDOW_DAYS after the note was written, after
     * which it locks to managers only. Server-side source of truth — the
     * frontend banners are UX affordances only.
     *
     * @return array{editable: bool, locked: bool, reason: string, days_left: int|null, age_days: int|null}
     */
    protected function editPermission(ClientNote $note, ?User $auth): array
    {
        if (! $auth) {
            return ['editable' => false, 'locked' => true, 'reason' => 'unauthenticated', 'days_left' => null, 'age_days' => null];
        }

        if ($auth->canDo('shifts.manageAny')) {
            return ['editable' => true, 'locked' => false, 'reason' => 'manager', 'days_left' => null, 'age_days' => null];
        }

        if ((int) $note->user_id !== (int) $auth->id) {
            return ['editable' => false, 'locked' => true, 'reason' => 'not_owner', 'days_left' => null, 'age_days' => null];
        }

        $reference = $note->created_at;
        $ageDays = $reference
            ? (int) $reference->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : 0;

        if ($ageDays >= self::EDIT_WINDOW_DAYS) {
            return ['editable' => false, 'locked' => true, 'reason' => 'window_closed', 'days_left' => 0, 'age_days' => $ageDays];
        }

        return [
            'editable' => true,
            'locked' => false,
            'reason' => 'within_window',
            'days_left' => self::EDIT_WINDOW_DAYS - $ageDays,
            'age_days' => $ageDays,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function shiftPayload(?Shift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        return [
            'id' => $shift->id,
            'starts_at' => optional($shift->starts_at)->toISOString(),
            'ends_at' => optional($shift->ends_at)->toISOString(),
            'shift_type' => $shift->shift_type,
            'label' => $this->shiftLabel($shift),
        ];
    }

    protected function shiftLabel(Shift $shift): string
    {
        if ($shift->shift_type) {
            return ucwords(str_replace('_', ' ', (string) $shift->shift_type));
        }

        return optional($shift->starts_at)->format('H:i') ?? 'Shift';
    }

    /**
     * Catalogue data for the hero filters + the add-note wizard selects.
     *
     * @return array<string, mixed>
     */
    protected function catalogue(User $auth): array
    {
        $orgId = $auth->organization_id;

        $clients = Client::query()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'site_id'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'site_id' => $c->site_id,
            ])->values();

        $staff = User::staff()
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ])->values();

        // Sites carry tenant_id (not organization_id), left unscoped to match the
        // rostering filter dropdowns.
        $sites = Site::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])->values();

        // Shifts feed the wizard's shift select + coverage-gap detection. Scope to
        // this org's clients over a recent + slightly-upcoming window.
        $clientIds = $clients->pluck('id');
        $shifts = Shift::query()
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('starts_at')
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('starts_at', [now()->subDays(30), now()->addDays(14)])
            ->with('staff:id,name,role')
            ->orderBy('starts_at')
            ->limit(800)
            ->get(['id', 'client_id', 'site_id', 'user_id', 'shift_type', 'starts_at', 'ends_at', 'status'])
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'client_id' => $s->client_id,
                'site_id' => $s->site_id,
                'user_id' => $s->user_id,
                'shift_type' => $s->shift_type,
                'label' => $this->shiftLabel($s),
                'starts_at' => optional($s->starts_at)->toISOString(),
                'ends_at' => optional($s->ends_at)->toISOString(),
                'staff' => $s->staff ? ['id' => $s->staff->id, 'name' => $s->staff->name] : null,
            ])->values();

        return [
            'clients' => $clients,
            'staff' => $staff,
            'sites' => $sites,
            'shifts' => $shifts,
        ];
    }
}
