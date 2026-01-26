<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\ServiceContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('calendar.viewAny'), 403);

        $canManageAny = $auth->canDo('shifts.manageAny');

        // Provide service contexts to allow shift creation/editing to capture
        // the service setting (residential / home support / respite) for audit.
        $serviceContexts = ServiceContext::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'is_active']);

        $staff = [];
        $clients = [];

        if ($canManageAny) {
            $staff = User::staff()
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            $clients = Client::query()
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name', 'service_context_id']);
        }

        return inertia('calendar/index', [
            'canManageAny' => $canManageAny,
            'staff' => $staff,
            'clients' => $clients,
            'serviceContexts' => $serviceContexts,
            'defaultServiceContextId' => ServiceContext::defaultId(),
        ]);
    }

    /**
     * JSON feed for FullCalendar.
     */
    public function events(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('calendar.viewAny'), 403);

        $data = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);

        $canManageAny = $auth->canDo('shifts.manageAny');

        // FullCalendar supplies an inclusive start and an exclusive end.
        // Use an overlap query so shifts that start before the range but
        // overlap it are still included.
        $query = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'staff:id,name',
                'serviceContext:id,name,type,is_active',
            ])
            ->where('starts_at', '<', $data['end'])
            ->where('ends_at', '>', $data['start']);

        if (!$canManageAny) {
            $query->where('user_id', $auth->id);
        } else {
            if (!empty($data['staff_id'])) {
                $query->where('user_id', $data['staff_id']);
            }
            if (!empty($data['client_id'])) {
                $query->where('client_id', $data['client_id']);
            }
        }

        $shifts = $query->get();

        return response()->json(
            $shifts->map(function (Shift $shift) use ($canManageAny) {
                $clientName = $shift->client ? ($shift->client->first_name . ' ' . $shift->client->last_name) : 'Client';
                $staffName = $shift->staff ? $shift->staff->name : 'Staff';

                $title = $canManageAny ? ($clientName . ' · ' . $staffName) : $clientName;

                return [
                    'id' => $shift->id,
                    'title' => $title,
                    // Send ISO-8601 strings so FullCalendar parses reliably.
                    'start' => optional($shift->starts_at)->toIso8601String(),
                    'end' => optional($shift->ends_at)->toIso8601String(),
                    'extendedProps' => [
                        'client_id' => $shift->client_id,
                        'service_context_id' => $shift->service_context_id,
                        'service_context' => $shift->serviceContext ? $shift->serviceContext->name : null,
                        'user_id' => $shift->user_id,
                        'location' => $shift->location,
                        'notes' => $shift->notes,
                        'status' => $shift->status,
                        'client' => $clientName,
                        'staff' => $staffName,
                    ],
                ];
            })->values()
        );
    }

    public function storeShift(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        // If not explicitly provided, inherit the client's service context.
        // This keeps service setting consistent for audit trails.
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If still not set, apply organisation default service context (if configured).
        if (empty($data['service_context_id'])) {
            $data['service_context_id'] = ServiceContext::defaultId();
        }

        $conflicts = Shift::query()
            ->where(function ($q) use ($data) {
                $q->where('user_id', $data['user_id'])->orWhere('client_id', $data['client_id']);
            })
            ->where('starts_at', '<', $data['ends_at'])
            ->where('ends_at', '>', $data['starts_at'])
            ->exists();

        abort_unless(!$conflicts, 422, 'Conflicting shift detected for this staff member or client during that time.');

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

        $shift->load(['client:id,first_name,last_name', 'staff:id,name']);

        return response()->json([
            'ok' => true,
            'shift' => $shift,
        ], 201);
    }

    public function updateShift(Request $request, Shift $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.update'), 403);

        // Staff can edit only own shifts unless manageAny
        if (!$auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }

        // Support partial updates (drag/drop resize sends only times)
        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'service_context_id' => ['sometimes', 'nullable', 'integer', 'exists:service_contexts,id'],
            'user_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'in:scheduled,completed,cancelled'],
            'tasks' => ['sometimes', 'array'],
            'tasks.*.id' => ['sometimes', 'integer', 'exists:shift_tasks,id'],
            'tasks.*.label' => ['required_with:tasks', 'string', 'max:255'],
        ]);

        // If the client is changed but service_context_id isn't explicitly set,
        // inherit the new client's service context.
        if (array_key_exists('client_id', $data) && !array_key_exists('service_context_id', $data)) {
            $data['service_context_id'] = Client::query()
                ->whereKey($data['client_id'])
                ->value('service_context_id');
        }

        // If one of starts/ends provided, require both and ensure ends > starts
        $hasStart = array_key_exists('starts_at', $data);
        $hasEnd = array_key_exists('ends_at', $data);

        if ($hasStart || $hasEnd) {
            abort_unless($hasStart && $hasEnd, 422, 'Both starts_at and ends_at are required when updating time.');
            $start = Carbon::parse($data['starts_at']);
            $end = Carbon::parse($data['ends_at']);
            abort_unless($end->greaterThan($start), 422, 'ends_at must be after starts_at.');
        }

        // Conflict check when we have enough data
        $resolvedClientId = $data['client_id'] ?? $shift->client_id;
        $resolvedUserId = $data['user_id'] ?? $shift->user_id;
        $resolvedStart = Carbon::parse($data['starts_at'] ?? $shift->starts_at);
        $resolvedEnd = Carbon::parse($data['ends_at'] ?? $shift->ends_at);

        $conflicts = Shift::query()
            ->where('id', '!=', $shift->id)
            ->where(function ($q) use ($resolvedUserId, $resolvedClientId) {
                $q->where('user_id', $resolvedUserId)->orWhere('client_id', $resolvedClientId);
            })
            ->where('starts_at', '<', $resolvedEnd)
            ->where('ends_at', '>', $resolvedStart)
            ->exists();

        abort_unless(!$conflicts, 422, 'Conflicting shift detected for this staff member or client during that time.');

        // If the client changes and service context is not explicitly set,
        // inherit from the client to keep classification consistent.
        if (array_key_exists('client_id', $data) && !array_key_exists('service_context_id', $data)) {
            $data['service_context_id'] = Client::query()
                ->whereKey($resolvedClientId)
                ->value('service_context_id');
        }

        DB::transaction(function () use ($shift, $data) {
            $shift->update(\Illuminate\Support\Arr::except($data, ['tasks']));

            if (array_key_exists('tasks', $data)) {
                $existing = $shift->tasks()->get()->keyBy('id');
                $incoming = collect($data['tasks'] ?? [])
                    ->map(fn ($t, $i) => [
                        'id' => $t['id'] ?? null,
                        'label' => (string) ($t['label'] ?? ''),
                        'sort_order' => $i,
                    ])
                    ->filter(fn ($t) => trim($t['label']) !== '')
                    ->values();

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

        $shift->load(['client:id,first_name,last_name', 'staff:id,name', 'tasks']);

        return response()->json([
            'ok' => true,
            'shift' => $shift,
        ]);
    }
}
