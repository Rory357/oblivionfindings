<?php

namespace App\Http\Controllers;

use App\Models\Client;
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

        $date = $request->query('date');
        $day = $date ? now()->parse($date)->startOfDay() : now()->startOfDay();
        $next = (clone $day)->addDay();

        $query = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->whereBetween('starts_at', [$day, $next])
            ->orderBy('starts_at');

        if (!$auth->canDo('shifts.manageAny')) {
            // Assigned-only access: only their own shifts
            $query->where('user_id', $auth->id);
        }

        $shifts = $query->paginate(25)->withQueryString();

        return inertia('shifts/index', [
            'shifts' => $shifts,
            'filters' => [
                'date' => $day->toDateString(),
            ],
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

        $shift->load(['client:id,first_name,last_name,site_id', 'staff:id,name,email', 'tasks']);

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
            'can' => [
                'add_note' => $auth->canDo('timeline.create'),
                'mark_tasks' => true,
            ],
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        $staff = User::query()->orderBy('name')->get(['id', 'name', 'email']);

        return inertia('shifts/create', [
            'clients' => $clients,
            'staff' => $staff,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        // Conflict check: staff or client overlap
        $conflicts = Shift::query()
            ->where(function ($q) use ($data) {
                $q->where('user_id', $data['user_id'])
                  ->orWhere('client_id', $data['client_id']);
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

        $client = Client::query()->find($shift->client_id);
        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'shift', $shift, $client, [
            'title' => 'Shift created',
            'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
            'url' => url("/shifts/{$shift->id}"),
            'target_user_ids' => [$shift->user_id],
        ]);

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

        $shift->load(['client:id,first_name,last_name', 'staff:id,name,email', 'tasks']);
        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);
        $staff = User::query()->orderBy('name')->get(['id', 'name', 'email']);

        return inertia('shifts/edit', [
            'shift' => $shift,
            'clients' => $clients,
            'staff' => $staff,
        ]);
    }

    public function update(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.id' => ['sometimes', 'integer', 'exists:shift_tasks,id'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
            'tasks.*.is_completed' => ['sometimes', 'boolean'],
        ]);

        // Conflict check: staff or client overlap (ignore self)
        $conflicts = Shift::query()
            ->where('id', '!=', $shift->id)
            ->where(function ($q) use ($data) {
                $q->where('user_id', $data['user_id'])
                  ->orWhere('client_id', $data['client_id']);
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
        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'shift', $shift, $client, [
            'title' => 'Shift updated',
            'body' => $client ? ("Client: {$client->first_name} {$client->last_name}") : null,
            'url' => url("/shifts/{$shift->id}"),
            'target_user_ids' => [$shift->user_id],
        ]);

        return redirect()->route('shifts.index')->with('success', 'Shift updated.');
    }
}
