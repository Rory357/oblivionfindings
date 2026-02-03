<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\IncidentTemplate;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.viewAny') || $auth->canDo('shifts.viewAssigned')), 403);

        $from = $request->query('from');
        $to = $request->query('to');

        if ($from) {
            $start = now()->parse($from)->startOfDay();
        } elseif ($to) {
            $start = now()->parse($to)->startOfDay();
        } else {
            $start = now()->startOfDay();
        }

        if ($to) {
            $end = now()->parse($to)->endOfDay();
        } elseif ($from) {
            $end = now()->parse($from)->endOfDay();
        } else {
            $end = now()->endOfDay();
        }

        $query = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->whereBetween('starts_at', [$start, $end])
            ->orderBy('starts_at');

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->query('client_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->query('assigned') === 'assigned') {
            $query->whereNotNull('user_id');
        } elseif ($request->query('assigned') === 'unassigned') {
            $query->whereNull('user_id');
        }

        if ($request->filled('q')) {
            $q = $request->query('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('location', 'like', "%{$q}%")
                    ->orWhereHas('client', function ($cq) use ($q) {
                        $cq->where('first_name', 'like', "%{$q}%")
                            ->orWhere('last_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('staff', function ($sq) use ($q) {
                        $sq->where('name', 'like', "%{$q}%")
                            ->orWhere('email', 'like', "%{$q}%");
                    });
            });
        }

        if (!$auth->canDo('shifts.manageAny')) {
            // Assigned-only access: only their own shifts
            $query->where('user_id', $auth->id);
        }

        $shifts = $query->paginate(25)->withQueryString();

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $staff = User::staff()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return inertia('shifts/index', [
            'shifts' => $shifts,
            'filters' => [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'status' => $request->query('status'),
                'client_id' => $request->query('client_id'),
                'user_id' => $request->query('user_id'),
                'assigned' => $request->query('assigned'),
                'q' => $request->query('q'),
            ],
            'clients' => $clients,
            'staff' => $staff,
            'statuses' => ['draft', 'scheduled', 'in_progress', 'completed', 'cancelled'],
            'canCreate' => $auth->canDo('shifts.create'),
        ]);
    }

    public function show(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.viewAny') || $auth->canDo('shifts.viewAssigned')), 403);

        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $shift->load([
            'client:id,first_name,last_name,site_id',
            'staff:id,name,email',
            'tasks',
            'serviceContext:id,name,type,is_active',
        ]);

        // Pinned handover notes for this client
        $handover = \App\Models\TimelineEvent::query()
            ->where('client_id', $shift->client_id)
            ->where('type', 'handover')
            ->where('is_pinned', true)
            ->orderByDesc('occurred_at')
            ->with(['actor:id,name'])
            ->limit(5)
            ->get();

        // Notes linked to this shift
        $notes = \App\Models\TimelineEvent::query()
            ->where('shift_id', $shift->id)
            ->orderByDesc('occurred_at')
            ->with(['actor:id,name'])
            ->limit(100)
            ->get();

        $incidents = ClientIncident::query()
            ->where('shift_id', $shift->id)
            ->with(['reporter:id,name', 'attachments'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $incidentTemplates = IncidentTemplate::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();


        return inertia('shifts/show', [
            'shift' => $shift,
            'handover' => $handover->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            ])->values(),
            'notes' => $notes->map(fn($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'occurred_at' => optional($e->occurred_at)->toISOString(),
                'subject' => $e->subject,
                'body' => $e->body,
                'meta' => $e->meta ?? [],
                'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
            ])->values(),
            'incidents' => $incidents,
            'incidentTemplates' => $incidentTemplates,
            'can' => [
                'add_note' => $auth->canDo('timeline.create'),
                'create_incident' => $auth->canDo('incidents.create'),
                'mark_tasks' => true,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'service_context_id']);
        $staff = User::staff()->orderBy('name')->get(['id', 'name', 'email']);

        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        $defaultClientId = $request->query('client_id');

        return inertia('shifts/create', [
            'clients' => $clients,
            'staff' => $staff,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
            'defaultClientId' => $defaultClientId,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            // user_id may be null to create an "open" / unassigned shift for rostering.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,scheduled,in_progress,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        // If not explicitly provided, inherit the client's service context.
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        // Conflict check: staff or client overlap
        $conflicts = Shift::query()
            ->where(function ($q) use ($data) {
                if (!empty($data['user_id'])) {
                    $q->where('user_id', $data['user_id']);
                }
                $q->orWhere('client_id', $data['client_id']);
            })
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        if ($conflicts) {
            return back()->withErrors([
                'starts_at' => 'Conflicting shift detected for this staff member or client during that time.',
            ])->withInput();
        }

        $shift = DB::transaction(function () use ($auth, $data) {
            $shift = Shift::create([
                ...\Illuminate\Support\Arr::except($data, ['tasks']),
                'status' => $data['status'] ?? 'scheduled',
                'created_by' => $auth->id,
            ]);

            $tasks = collect($data['tasks'] ?? [])
                ->map(fn ($t, $i) => ['label' => (string) ($t['label'] ?? ''), 'sort_order' => $i])
                ->filter(fn ($t) => trim($t['label']) !== '')
                ->values();

            foreach ($tasks as $t) {
                ShiftTask::create([
                    'shift_id' => $shift->id,
                    'label' => $t['label'],
                    'sort_order' => $t['sort_order'],
                ]);
            }
            return $shift;
        });

        // Notify assigned staff only (open shifts have no assignee).
        if (!empty($shift->user_id)) {
            $client = Client::query()->find($shift->client_id);
        $targetUserIds = $shift->user_id ? [$shift->user_id] : [];
        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift', $shift, $client, [
                'title' => 'Shift created',
                'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
                'url' => url("/shifts/{$shift->id}"),
            'target_user_ids' => $targetUserIds,
            ]);
        }

        return redirect()->route('shifts.index')->with('success', 'Shift created.');
    }

    public function edit(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        // Staff can edit only own shifts unless manageAny
        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $shift->load(['client:id,first_name,last_name,service_context_id', 'staff:id,name,email', 'tasks', 'serviceContext:id,name,type,is_active']);
        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'service_context_id']);
        $staff = User::staff()->orderBy('name')->get(['id', 'name', 'email']);

        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        return inertia('shifts/edit', [
            'shift' => $shift,
            'clients' => $clients,
            'staff' => $staff,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    public function update(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        // Lock: once a shift is completed, treat it as immutable (auditable record).
        if ($shift->status === 'completed') {
            return back()->with('error', 'This shift has been completed and is now locked.');
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            // user_id may be null to keep this as an "open" / unassigned shift.
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,scheduled,in_progress,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.id' => ['sometimes', 'integer', 'exists:shift_tasks,id'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.is_completed' => ['sometimes', 'boolean'],
        ]);

        // If not explicitly provided, inherit the client's service context.
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        // Conflict check: staff or client overlap (ignore self)
        $conflicts = Shift::query()
            ->where('id', '!=', $shift->id)
            ->where(function ($q) use ($data) {
                if (!empty($data['user_id'])) {
                    $q->where('user_id', $data['user_id']);
                }
                $q->orWhere('client_id', $data['client_id']);
            })
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        if ($conflicts) {
            return back()->withErrors([
                'starts_at' => 'Conflicting shift detected for this staff member or client during that time.',
            ])->withInput();
        }

        DB::transaction(function () use ($auth, $shift, $data) {
            $shift->update(\Illuminate\Support\Arr::except($data, ['tasks']));

            if (array_key_exists('tasks', $data)) {
                // Replace tasks list (keep completion state if matching by id)
                $existing = $shift->tasks()->get()->keyBy('id');
                $incoming = collect($data['tasks'] ?? [])
                    ->map(fn ($t, $i) => [
                        'id' => $t['id'] ?? null,
                        'label' => (string) ($t['label'] ?? ''),
                        'sort_order' => $i,
                    ])
                    ->filter(fn ($t) => trim($t['label']) !== '')
                    ->values();

                // Delete removed
                $keepIds = $incoming->pluck('id')->filter()->all();
                $shift->tasks()->whereNotIn('id', $keepIds)->delete();

                foreach ($incoming as $t) {
                    if ($t['id'] && $existing->has($t['id'])) {
                        $existing[$t['id']]->update([
                            'label' => $t['label'],
                            'sort_order' => $t['sort_order'],
                        ]);
                    } else {
                        ShiftTask::create([
                            'shift_id' => $shift->id,
                            'label' => $t['label'],
                            'sort_order' => $t['sort_order'],
                        ]);
                    }
                }
            }
        });

        $client = Client::query()->find($shift->client_id);
        $targetUserIds = $shift->user_id ? [$shift->user_id] : [];
        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'shift', $shift, $client, [
            'title' => 'Shift updated',
            'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
            'url' => url("/shifts/{$shift->id}"),
            'target_user_ids' => $targetUserIds,
        ]);

        return redirect()->route('shifts.index')->with('success', 'Shift updated.');
    }


    public function start(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        if (!in_array($shift->status, ['scheduled', 'draft'], true)) {
            return back()->withErrors(['status' => 'Only scheduled shifts can be started.']);
        }

        $shift->update([
            'status' => 'in_progress',
            'actual_starts_at' => $shift->actual_starts_at ?? now(),
            'started_by' => $shift->started_by ?? $auth->id,
        ]);

        return back()->with('success', 'Shift started.');
    }

    public function complete(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        if (!in_array($shift->status, ['scheduled', 'in_progress'], true)) {
            return back()->withErrors(['status' => 'Only scheduled or in-progress shifts can be completed.']);
        }

        $data = $request->validate([
            'final_note_subject' => ['nullable', 'string', 'max:255'],
            // Required only if no other shift notes exist.
            'final_note_body' => ['nullable', 'string', 'max:20000'],
            'allow_incomplete_tasks' => ['nullable', 'boolean'],
            'incomplete_tasks_reason' => ['nullable', 'string', 'max:2000'],
            'create_timesheet' => ['nullable', 'boolean'],
        ]);

        $shift->loadMissing(['tasks', 'client']);
        $incompleteTasks = $shift->tasks->where('is_completed', false)->values();

        // Enforce: a shift must have at least one progress/shift note OR a completion summary.
        $existingNoteCount = \App\Models\ClientNote::query()
            ->where('shift_id', $shift->id)
            ->whereIn('type', ['progress_note', 'shift_note'])
            ->count();

        $finalBody = trim((string)($data['final_note_body'] ?? ''));
        if ($finalBody === '' && $existingNoteCount === 0) {
            return back()->withErrors([
                'final_note_body' => 'Add at least one progress note during the shift or provide a shift summary note to complete the shift.',
            ]);
        }

        $allowIncomplete = (bool)($data['allow_incomplete_tasks'] ?? false);
        if ($incompleteTasks->count() > 0 && !$allowIncomplete) {
            return back()->withErrors([
                'allow_incomplete_tasks' => 'This shift still has incomplete tasks. Complete all tasks or allow completion with a reason.',
            ]);
        }
        if ($incompleteTasks->count() > 0 && $allowIncomplete && empty(trim((string)($data['incomplete_tasks_reason'] ?? '')))) {
            return back()->withErrors([
                'incomplete_tasks_reason' => 'Please provide a reason for completing with incomplete tasks.',
            ]);
        }

        DB::transaction(function () use ($auth, $shift, $data, $incompleteTasks, $allowIncomplete, $finalBody) {
            $now = now();

            $shift->update([
                'status' => 'completed',
                'actual_starts_at' => $shift->actual_starts_at ?? $now,
                'actual_ends_at' => $now,
                'started_by' => $shift->started_by ?? $auth->id,
                'completed_by' => $auth->id,
            ]);

            // Create a shift summary note (auditable via ClientNote + TimelineEvent)
            $subject = trim((string)($data['final_note_subject'] ?? 'Shift summary'));
            $body = $finalBody !== ''
                ? $finalBody
                : 'Shift completed — see shift notes for details.';

            $note = \App\Models\ClientNote::create([
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'user_id' => $auth->id,
                'type' => 'shift_note',
                'subject' => $subject,
                'body' => $body,
                'occurred_at' => $now,
                'visibility' => 'internal',
                'is_pinned' => false,
            ]);

            \App\Models\TimelineEvent::create([
                'source_type' => \App\Models\ClientNote::class,
                'source_id' => $note->id,
                'occurred_at' => $now,
                'type' => 'shift_note',
                'actor_user_id' => $auth->id,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'site_id' => $shift->client?->site_id,
                'subject' => $subject,
                'body' => $body,
                'meta' => array_filter([
                    'completed_with_incomplete_tasks' => $incompleteTasks->count() > 0 ? true : null,
                    'incomplete_tasks_reason' => $allowIncomplete ? (string)($data['incomplete_tasks_reason'] ?? null) : null,
                    'incomplete_task_count' => $incompleteTasks->count() ?: null,
                ]),
                'visibility' => 'internal',
                'is_pinned' => false,
                'created_by' => $auth->id,
            ]);

            // Auto-create timesheet (optional)
            $wantTimesheet = (bool)($data['create_timesheet'] ?? true);
            if ($wantTimesheet && $auth->canDo('timesheets.create')) {
                $exists = \App\Models\Timesheet::query()->where('shift_id', $shift->id)->exists();
                if (!$exists) {
                    $startsAt = $shift->actual_starts_at ?? $shift->starts_at ?? $now;
                    $endsAt = $shift->actual_ends_at ?? $shift->ends_at ?? $now;
                    \App\Models\Timesheet::create([
                        'user_id' => $shift->user_id,
                        'client_id' => $shift->client_id,
                        'shift_id' => $shift->id,
                        'work_date' => $startsAt->toDateString(),
                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,
                        'break_minutes' => 0,
                        'notes' => null,
                        'status' => 'draft',
                        'created_by' => $auth->id,
                    ]);
                }
            }
        });

        return back()->with('success', 'Shift completed.');
    }

    public function assign(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);

        // Lock: once completed, immutable.
        if ($shift->status === 'completed') {
            return back()->with('error', 'This shift has been completed and is now locked.');
        }

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'return_to' => ['nullable', 'string'],
        ]);

        // Only allow assigning staff users
        abort_unless(User::staff()->whereKey($data['user_id'])->exists(), 404);

        // Check staff overlap conflicts
        $conflicts = Shift::query()
            ->where('id', '!=', $shift->id)
            ->where('user_id', $data['user_id'])
            ->where('starts_at', '<', $shift->ends_at)
            ->where('ends_at', '>', $shift->starts_at)
            ->exists();

        if ($conflicts) {
            return back()->withErrors([
                'user_id' => 'Conflicting shift detected for this staff member during that time.',
            ]);
        }

        $shift->update(['user_id' => $data['user_id']]);

        return redirect($data['return_to'] ?? url('/rostering'))->with('success', 'Shift assigned.');
    }

    public function unassign(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.manageAny'), 403);

        if ($shift->status === 'completed') {
            return back()->with('error', 'This shift has been completed and is now locked.');
        }

        $returnTo = $request->input('return_to') ?: url('/rostering');
        $shift->update(['user_id' => null]);

        return redirect($returnTo)->with('success', 'Shift unassigned.');
    }
}
