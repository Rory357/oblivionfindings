<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('calendar.viewAny'), 403);

        $canManageAny = $auth->canDo('shifts.manageAny');

        $staff = [];
        $clients = [];

        if ($canManageAny) {
            $staff = User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            $clients = Client::query()
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name']);
        }

        return inertia('calendar/index', [
            'canManageAny' => $canManageAny,
            'staff' => $staff,
            'clients' => $clients,
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

        $query = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name'])
            ->whereBetween('starts_at', [$data['start'], $data['end']]);

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
                    'start' => $shift->starts_at,
                    'end' => $shift->ends_at,
                    'extendedProps' => [
                        'client_id' => $shift->client_id,
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
        ]);

        $shift = Shift::create([
            ...$data,
            'status' => $data['status'] ?? 'scheduled',
            'created_by' => $auth->id,
        ]);

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
            'user_id' => ['sometimes', 'required', 'integer', 'exists:users,id'],
            'starts_at' => ['sometimes', 'required', 'date'],
            'ends_at' => ['sometimes', 'required', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'nullable', 'in:scheduled,completed,cancelled'],
        ]);

        // If one of starts/ends provided, require both and ensure ends > starts
        $hasStart = array_key_exists('starts_at', $data);
        $hasEnd = array_key_exists('ends_at', $data);

        if ($hasStart || $hasEnd) {
            abort_unless($hasStart && $hasEnd, 422, 'Both starts_at and ends_at are required when updating time.');
            $start = Carbon::parse($data['starts_at']);
            $end = Carbon::parse($data['ends_at']);
            abort_unless($end->greaterThan($start), 422, 'ends_at must be after starts_at.');
        }

        $shift->update($data);
        $shift->load(['client:id,first_name,last_name', 'staff:id,name']);

        return response()->json([
            'ok' => true,
            'shift' => $shift,
        ]);
    }
}
