<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ShiftNoteController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /**
     * Author edit window: a support worker may edit their own note for this many
     * days after it was written; after that only a manager can. Shared with the
     * frontend permission affordances (see note-detail-dialog.tsx).
     */
    public const EDIT_WINDOW_DAYS = 7;

    /** Note types surfaced in the redesigned workspace. */
    public const TYPES = ['shift_note', 'progress_note', 'handover', 'incident', 'note'];

    public const RESULT_LIMIT = 400;

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
        $filters = $this->validatedFilters($request);
        [$weekStart, $endExclusive, $tz] = $this->week($filters['week']);
        $filters['week'] = $weekStart->toDateString();
        $query = $this->filteredNotes($auth, $filters, false);
        $aggregate = (clone $query)->selectRaw('COUNT(*) AS total, COALESCE(SUM(is_flagged = 1), 0) AS flagged, COALESCE(SUM(reviewed_at IS NULL), 0) AS awaiting, COALESCE(SUM(reviewed_at IS NOT NULL), 0) AS reviewed')->first();
        $summary = collect(['total', 'flagged', 'awaiting', 'reviewed'])
            ->mapWithKeys(fn ($key) => [$key => (int) $aggregate->getAttribute($key)])->all();
        $activeTotal = $filters['status'] === 'all' ? $summary['total'] : $summary[$filters['status']];
        $filters['page'] = min($filters['page'], max(1, (int) ceil($activeTotal / self::RESULT_LIMIT)));
        $page = $this->applyStatus((clone $query), $filters['status'])
            ->with(['user:id,name', 'client:id,first_name,last_name,site_id', 'client.site:id,name',
                'shift:id,starts_at,ends_at,shift_type,client_id,user_id,site_id', 'reviewer:id,name', 'editor:id,name'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::RESULT_LIMIT, ['*'], 'page', $filters['page'], $activeTotal)->appends($filters);
        $notes = $page->getCollection()->map(fn (ClientNote $note) => $this->mapNote($note, $auth))->values();

        return inertia('operations/shift-notes/Index', [
            'notes' => $notes, 'weekStart' => $weekStart->toDateString(),
            'weekEnd' => $endExclusive->copy()->subDay()->toDateString(), 'workerTimezone' => $tz,
            'filters' => $filters, 'summary' => $summary,
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(),
                'to' => $page->lastItem(), 'links' => $page->linkCollection()->all()],
            'results' => ['total' => $activeTotal, 'shown' => $notes->count(), 'limit' => self::RESULT_LIMIT,
                'truncated' => $activeTotal > $notes->count()],
            'evidence' => ['state' => $summary['total'] === 0 ? 'no_records' : 'recorded',
                'basis' => 'current_authorized_effective_shift_week', 'scope' => 'permitted_sites',
                'search_scope' => 'visible_note_body_author_client_site', 'summary_scope' => 'filters_except_status',
                'page_scope' => 'filters_including_status', 'private_notes' => 'author_or_progress_notes_review',
                'export' => 'complete_filtered_result', 'timezone' => $tz,
                'period_start' => $weekStart->copy()->utc()->toIso8601String(),
                'period_end_exclusive' => $endExclusive->copy()->utc()->toIso8601String(),
                'checked_at' => now()->utc()->toIso8601String(), 'complete' => true],
            'catalogue' => $this->catalogue($auth, $weekStart, $endExclusive->copy()->subMicrosecond()),
            'can' => ['create' => $auth->canDo('shifts.viewAny'), 'manage' => $auth->canDo('shifts.manageAny'),
                'flag' => $auth->canDo('progress_notes.update') || $auth->canDo('progress_notes.review'),
                'review' => $auth->canDo('progress_notes.review')],
            'currentUser' => ['id' => $auth->id, 'name' => $auth->name, 'is_manager' => $auth->canDo('shifts.manageAny')],
        ]);
    }

    public function store(Request $request)
    {
        $rootEntry = $this->beginCommand($request);
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
        $validated = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'body' => ['required', 'string', 'max:5000'], 'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'], 'is_private' => ['nullable', 'boolean'],
        ]);
        $hint = $this->legacyScope($this->siteAccess->applyShiftScope(Shift::query(), $auth, []), $auth)
            ->whereNotNull('client_id')->where('status', '!=', 'cancelled')->findOrFail($validated['shift_id']);
        $outcome = $this->commandTransaction(function () use ($auth, $hint, $validated): array {
            [$shift, $client] = $this->lockSource($hint);
            abort_if($shift->status === 'cancelled', 404);
            $actor = $this->currentActor($auth);
            $this->assertCurrentSource($actor, $shift, $client);
            $flagged = (bool) ($validated['is_flagged'] ?? false);
            $expected = ['client_id' => $shift->client_id, 'shift_id' => $shift->id, 'user_id' => $actor->id,
                'type' => $validated['type'], 'body' => $validated['body'], 'occurred_at' => $shift->starts_at ?? now(),
                'visibility' => 'internal', 'is_flagged' => $flagged,
                'flagged_reason' => $flagged ? ($validated['flagged_reason'] ?? null) : null,
                'is_private' => (bool) ($validated['is_private'] ?? false), 'appears_on_timeline' => true];
            $note = new ClientNote($expected);
            $note->setRelation('client', $client);
            abort_unless($note->save() === true, 409, 'The note could not be saved. Refresh and review the current record.');
            $persisted = $this->persistedNote($note, $expected);

            return ['actor_id' => (int) $actor->id, 'note' => $persisted, 'changed' => true];
        });

        return $this->committedResult(redirect()->back()->with('success', 'Shift note added.'), $rootEntry, 'create', $outcome);
    }

    public function update(Request $request, $note)
    {
        $rootEntry = $this->beginCommand($request);
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
        $note = $this->visibleNotes($auth, [])->findOrFail($note);
        $permission = $this->editPermission($note, $auth);
        abort_unless($permission['editable'], 403, $permission['reason'] === 'window_closed'
            ? 'The edit window for this note has closed — only a manager can edit it now.'
            : 'You are not authorized to edit this note.');
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'body' => ['required', 'string', 'max:5000'], 'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'], 'is_private' => ['nullable', 'boolean'],
        ]);
        $outcome = $this->mutateNote($auth, $note, function (ClientNote $locked, User $actor) use ($validated): array {
            abort_unless($this->editPermission($locked, $actor, true)['editable'], 403);
            $flagged = (bool) ($validated['is_flagged'] ?? false);

            return ['type' => $validated['type'], 'body' => $validated['body'], 'is_flagged' => $flagged,
                'flagged_reason' => $flagged ? ($validated['flagged_reason'] ?? null) : null,
                'is_private' => (bool) ($validated['is_private'] ?? $locked->is_private),
                'edited_at' => now(), 'edited_by' => $actor->id];
        });

        return $this->committedResult(redirect()->back()->with('success', 'Shift note updated.'), $rootEntry, 'update', $outcome);
    }

    public function export(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $filters = $this->validatedFilters($request);
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $notes = $this->filteredNotes($auth, $filters)
            ->with(['user:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return response()->streamDownload(function () use ($notes, $tz): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Date ('.$tz.')', 'Client', 'Author', 'Type', 'Content', 'Shift Start ('.$tz.')', 'Shift End ('.$tz.')', 'Flagged', 'Private'], ',', '"', '');
            foreach ($notes->lazy(200) as $note) {
                $safeCell = fn ($value) => preg_match('/^[=+@\-\t\r]/', (string) $value) ? "'".$value : $value;
                fputcsv($stream, array_map($safeCell, [
                    ($note->shift?->starts_at ?? $note->created_at)?->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                    $note->client ? trim($note->client->first_name.' '.$note->client->last_name) : '',
                    $note->user?->name ?? '',
                    $note->type ?? 'note',
                    $note->body ?? '',
                    $note->shift?->starts_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') ?? '',
                    $note->shift?->ends_at?->copy()->setTimezone($tz)->format('Y-m-d H:i') ?? '',
                    $note->is_flagged ? 'Yes' : 'No',
                    $note->is_private ? 'Yes' : 'No',
                ]), ',', '"', '');
            }
            fclose($stream);
        }, 'shift-notes-export-'.Carbon::now($tz)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function flag(Request $request, $note)
    {
        $rootEntry = $this->beginCommand($request);
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
        $note = $this->visibleNotes($auth, [])->findOrFail($note);
        $this->authorize('flag', $note);
        $data = $request->validate(['flagged_reason' => ['nullable', 'string', 'max:500']]);
        $outcome = $this->mutateNote($auth, $note, function (ClientNote $locked, User $actor) use ($data): array {
            abort_unless($actor->can('flag', $locked), 403);

            return ['is_flagged' => ! $locked->is_flagged,
                'flagged_reason' => $locked->is_flagged ? null : ($data['flagged_reason'] ?? 'Flagged for review')];
        });

        return $this->committedResult(redirect()->back()->with('success', $outcome['note']->is_flagged ? 'Note flagged.' : 'Flag removed.'), $rootEntry, 'flag', $outcome);
    }

    public function markReviewed(Request $request, $note)
    {
        $rootEntry = $this->beginCommand($request);
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);
        $note = $this->visibleNotes($auth, [])->findOrFail($note);
        $this->authorize('review', $note);
        $outcome = $this->mutateNote($auth, $note, function (ClientNote $locked, User $actor): array {
            abort_unless($actor->can('review', $locked), 403);

            return $locked->reviewed_at ? [] : ['reviewed_at' => now(), 'reviewed_by' => $actor->id];
        });

        return $this->committedResult(redirect()->back()->with('success', 'Note marked as reviewed.'), $rootEntry, 'review', $outcome);
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
            'can_flag' => $this->canMutateNoteAtSite($note, $auth) && $auth->can('flag', $note),
            'can_review' => $this->canMutateNoteAtSite($note, $auth) && $auth->can('review', $note),
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
    protected function editPermission(ClientNote $note, ?User $auth, bool $currentSiteConfirmed = false): array
    {
        if (! $auth) {
            return ['editable' => false, 'locked' => true, 'reason' => 'unauthenticated', 'days_left' => null, 'age_days' => null];
        }

        if (! $currentSiteConfirmed && ! $this->canMutateNoteAtSite($note, $auth)) {
            return ['editable' => false, 'locked' => true, 'reason' => 'site_access', 'days_left' => null, 'age_days' => null];
        }

        if ($auth->canDo('shifts.manageAny')) {
            return ['editable' => true, 'locked' => false, 'reason' => 'manager', 'days_left' => null, 'age_days' => null];
        }

        if ((int) $note->user_id !== (int) $auth->id) {
            return ['editable' => false, 'locked' => true, 'reason' => 'not_owner', 'days_left' => null, 'age_days' => null];
        }

        $reference = $note->created_at;
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $ageDays = $reference
            ? max(0, (int) $reference->copy()->setTimezone($tz)->startOfDay()->diffInDays(Carbon::now($tz)->startOfDay()))
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
    protected function catalogue(User $auth, Carbon $weekStart, Carbon $weekEnd): array
    {
        $clients = $this->legacyScope($this->siteAccess->applyClientScope(Client::query(), $auth, ['reports.viewAny']), $auth)
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'site_id'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'site_id' => $c->site_id,
            ])->values();

        $staff = $this->legacyScope($this->siteAccess->applyStaffScope(User::staff(), $auth, ['reports.viewAny']), $auth)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ])->values();

        $sites = $this->siteAccess->applySiteScope(Site::query(), $auth, ['reports.viewAny'])
            ->orderBy('name')->get(['id', 'name'])
            ->map(fn (Site $s) => ['id' => $s->id, 'name' => $s->name])->values();

        // Only shifts the actor may write notes for feed the create picker.
        $clientIds = $clients->pluck('id');
        $shiftQuery = $this->legacyScope($this->siteAccess->applyShiftScope(Shift::query(), $auth, []), $auth)
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('starts_at')
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('starts_at', [$weekStart->copy()->subDays(30)->utc(), $weekEnd->copy()->addDays(14)->utc()]);
        $shiftTotal = (clone $shiftQuery)->count();
        $shifts = $shiftQuery
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
            // Documentation coverage is independent of list/search filters and
            // its display cap, but never reveals a hidden/private note's existence.
            'note_shift_ids' => $this->filteredNotes($auth, ['week' => $weekStart->toDateString()])
                ->whereIn('shift_id', $shifts->pluck('id'))->distinct()->pluck('shift_id')
                ->map(fn ($id) => (int) $id)->values(),
            'shift_results' => ['total' => $shiftTotal, 'shown' => $shifts->count(), 'limit' => 800, 'truncated' => $shiftTotal > $shifts->count()],
        ];
    }

    protected function visibleNotes(User $auth, array $bypassPermissions = ['reports.viewAny']): Builder
    {
        return $this->legacyScope(ClientNote::query()->forUser($auth), $auth)
            ->whereHas('client', fn (Builder $client) => $this->siteAccess->applyClientScope($client, $auth, $bypassPermissions))
            ->whereHas('shift', fn (Builder $shift) => $this->siteAccess->applyShiftScope($shift, $auth, $bypassPermissions)
                ->whereColumn('shifts.client_id', 'client_notes.client_id'));
    }

    protected function canMutateNoteAtSite(ClientNote $note, User $auth): bool
    {
        return in_array((int) $note->client?->site_id, $this->siteAccess->accessibleSiteIds($auth, []), true);
    }

    protected function mutateNote(User $auth, ClientNote $note, \Closure $mutate): array
    {
        $hint = $this->legacyScope(Shift::query(), $auth)->findOrFail($note->shift_id);

        return $this->commandTransaction(function () use ($auth, $note, $hint, $mutate): array {
            [$shift, $client] = $this->lockSource($hint);
            $locked = $this->legacyScope(ClientNote::query(), $auth)->lockForUpdate()->findOrFail($note->id);
            abort_unless((int) $locked->shift_id === (int) $note->shift_id
                && (int) $locked->client_id === (int) $note->client_id
                && (int) $locked->client_id === (int) $shift->client_id, 404);
            $actor = $this->currentActor($auth);
            $this->assertCurrentSource($actor, $shift, $client);
            CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $locked): void {
                abort_unless($reads->query($this->legacyScope(ClientNote::query()->forUser($actor), $actor))->whereKey($locked->id)->exists(), 404);
            });
            $locked->setRelation('client', $client)->setRelation('shift', $shift);
            $before = $locked->getRawOriginal();
            $updates = $mutate($locked, $actor);
            if ($updates !== []) {
                $locked->fill($updates);
                abort_unless($locked->save() === true, 409, 'The note could not be saved. Refresh and review the current record.');
            }
            $persisted = $this->persistedNote($locked, $updates);

            return ['actor_id' => (int) $actor->id, 'note' => $persisted, 'changed' => $before !== $persisted->getRawOriginal()];
        });
    }

    private function legacyScope(Builder $query, User $actor): Builder
    {
        return $query->when($actor->organization_id, fn (Builder $scope) => $scope
            ->where($scope->qualifyColumn('organization_id'), $actor->organization_id));
    }

    private function commandTransaction(\Closure $command): array
    {
        try {
            return DB::transaction($command);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['shift_note' => 'The note is being updated. Refresh and try again.']);
        }
    }

    /** Identity hints select locks; the current locked source is authoritative. */
    private function lockSource(Shift $hint): array
    {
        app(WorkforceMutationGuard::class)->lock();
        $client = Client::query()->lockForUpdate()->findOrFail($hint->client_id);
        $shift = Shift::query()->lockForUpdate()->findOrFail($hint->id);
        abort_unless($shift->client_id === $hint->client_id && $shift->site_id === $hint->site_id
            && $shift->user_id === $hint->user_id && (int) $client->id === (int) $shift->client_id, 404);
        $shift->setRelation('client', $client);

        return [$shift, $client];
    }

    private function currentActor(User $hint): User
    {
        $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($hint, [
            'shifts.viewAny', 'shifts.manageAny', 'progress_notes.update', 'progress_notes.review',
        ]);
        abort_unless($actor->isApproved() && $actor->canDo('shifts.viewAny'), 403);

        return $actor;
    }

    private function assertCurrentSource(User $actor, Shift $shift, Client $client): void
    {
        // Shift scope includes raw correlated worker SQL. Lock each canonical
        // source explicitly; an outer locking SELECT cannot lock that raw tree.
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($actor, $shift, $client): void {
            abort_unless($client->site_id && ($shift->site_id === null || (int) $shift->site_id === (int) $client->site_id), 404);
            $siteId = $this->siteAccess->shiftSiteId($shift);
            abort_unless($siteId && in_array($siteId, $this->siteAccess->accessibleSiteIds($actor, [], $reads), true), 404);
            if ($shift->user_id !== null) {
                $worker = $this->siteAccess->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), $siteId);
                abort_unless($reads->query($worker)->exists(), 404);
            }
            abort_unless(! $actor->organization_id || (int) $shift->organization_id === (int) $actor->organization_id, 404);
        });
    }

    private function persistedNote(ClientNote $note, array $expected): ClientNote
    {
        $persisted = ClientNote::query()->lockForUpdate()->find($note->id);
        abort_unless($note->exists && $persisted, 409, 'The note could not be saved. Refresh and review the current record.');
        $expectedModel = new ClientNote($expected);
        foreach (array_keys($expected) as $key) {
            $actual = $persisted->getAttribute($key);
            $intended = $expectedModel->getAttribute($key);
            $equal = $actual instanceof \DateTimeInterface && $intended instanceof \DateTimeInterface
                ? $actual->getTimestamp() === $intended->getTimestamp()
                : (str_ends_with($key, '_id') && $actual !== null && $intended !== null
                    ? (int) $actual === (int) $intended : $actual === $intended);
            abort_unless($equal, 409, 'The note could not be saved. Refresh and review the current record.');
        }

        return $persisted;
    }

    private function beginCommand(Request $request): bool
    {
        $request->session()->forget('shift_note_result');
        try {
            return $this->isPhysicalRoot();
        } catch (Throwable) {
            return false;
        }
    }

    protected function isPhysicalRoot(): bool
    {
        return DB::connection()->transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
    }

    protected function committedResult(RedirectResponse $response, bool $rootEntry, string $action, array $outcome): RedirectResponse
    {
        if (! $rootEntry) {
            return $response;
        }
        try {
            if (! $this->isPhysicalRoot()) {
                return $response;
            }
            $note = $outcome['note'];
            $response->with('shift_note_result', ['action' => $action, 'actor_id' => $outcome['actor_id'],
                'note_id' => (int) $note->id, 'shift_id' => (int) $note->shift_id, 'client_id' => (int) $note->client_id,
                'changed' => $outcome['changed'], 'values_hash' => $this->valuesHash($note),
                'is_flagged' => (bool) $note->is_flagged, 'is_private' => (bool) $note->is_private,
                'edited_at' => $note->edited_at?->toISOString(), 'edited_by' => $note->edited_by === null ? null : (int) $note->edited_by,
                'reviewed_at' => $note->reviewed_at?->toISOString(), 'reviewed_by' => $note->reviewed_by === null ? null : (int) $note->reviewed_by]);
        } catch (Throwable $exception) {
            try {
                Log::warning('Committed Shift note result could not be presented', ['action' => $action, 'exception_class' => $exception::class]);
            } catch (Throwable) {
                // Presentation failure cannot reverse the committed command.
            }
        }

        return $response;
    }

    protected function valuesHash(ClientNote $note): string
    {
        return hash('sha256', json_encode(['type' => $note->type, 'body' => $note->body,
            'is_flagged' => (bool) $note->is_flagged, 'flagged_reason' => $note->flagged_reason,
            'is_private' => (bool) $note->is_private], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    protected function validatedFilters(Request $request): array
    {
        $input = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'], 'client_id' => ['nullable', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('date_from') ? ['after_or_equal:date_from'] : [])],
            'flagged' => ['nullable', 'boolean'], 'status' => ['nullable', 'in:all,flagged,awaiting,reviewed'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ['week' => $input['week'] ?? null, 'q' => trim($input['q'] ?? ''), 'type' => $input['type'] ?? null,
            'client_id' => isset($input['client_id']) ? (int) $input['client_id'] : null,
            'author_id' => isset($input['author_id']) ? (int) $input['author_id'] : null,
            'site_id' => isset($input['site_id']) ? (int) $input['site_id'] : null,
            'date_from' => $input['date_from'] ?? null, 'date_to' => $input['date_to'] ?? null,
            'flagged' => (bool) ($input['flagged'] ?? false), 'status' => $input['status'] ?? 'all', 'page' => (int) ($input['page'] ?? 1)];
    }

    private function week(?string $date): array
    {
        $tz = config('app.worker_timezone') ?: config('app.timezone', 'UTC');
        $start = ($date ? Carbon::parse($date, $tz) : Carbon::now($tz))->startOfWeek(Carbon::MONDAY);

        return [$start, $start->copy()->addWeek(), $tz];
    }

    private function applyStatus(Builder $query, string $status): Builder
    {
        return $query->when($status === 'flagged', fn ($query) => $query->where('is_flagged', true))
            ->when($status === 'awaiting', fn ($query) => $query->whereNull('reviewed_at'))
            ->when($status === 'reviewed', fn ($query) => $query->whereNotNull('reviewed_at'));
    }

    protected function filteredNotes(User $auth, array $filters, bool $includeStatus = true): Builder
    {
        [$weekStart, $endExclusive, $tz] = $this->week($filters['week'] ?? null);
        $start = $weekStart->copy()->utc();
        $end = $endExclusive->copy()->utc();
        $query = $this->visibleNotes($auth);
        $this->effectiveDateRange($query, $start, $end);
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $this->effectiveDateRange($query,
                ! empty($filters['date_from']) ? Carbon::parse($filters['date_from'], $tz)->startOfDay()->utc() : $start,
                ! empty($filters['date_to']) ? Carbon::parse($filters['date_to'], $tz)->startOfDay()->addDay()->utc() : $end);
        }
        $query->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->when($filters['client_id'] ?? null, fn ($query, $id) => $query->where('client_id', $id))
            ->when($filters['author_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['site_id'] ?? null, fn ($query, $id) => $query->whereHas('client', fn ($client) => $client->where('site_id', $id)))
            ->when(! empty($filters['flagged']), fn ($query) => $query->where('is_flagged', true));
        if ($includeStatus) {
            $this->applyStatus($query, $filters['status'] ?? 'all');
        }
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $pattern = '%'.addcslashes($search, '\\%_').'%';
                $searchQuery->where('body', 'like', $pattern)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $pattern))
                    ->orWhereHas('client', fn ($client) => $client->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$pattern]))
                    ->orWhereHas('client.site', fn ($site) => $site->where('name', 'like', $pattern));
            });
        }

        return $query;
    }

    protected function effectiveDateRange(Builder $query, Carbon $start, Carbon $end): void
    {
        $query->where(function (Builder $date) use ($start, $end): void {
            $date->whereHas('shift', fn ($shift) => $shift->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
                ->orWhere(fn ($fallback) => $fallback->whereHas('shift', fn ($shift) => $shift->whereNull('starts_at'))
                    ->where('created_at', '>=', $start)->where('created_at', '<', $end));
        });
    }
}
