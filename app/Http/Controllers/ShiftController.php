<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Http\Request;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $date = $request->query('date');
        $day = $date ? now()->parse($date)->startOfDay() : now()->startOfDay();
        $next = (clone $day)->addDay();

        $query = Shift::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->whereBetween('starts_at', [$day, $next])
            ->orderBy('starts_at');

        if (!$auth->canDo('shifts.manageAny')) {
            // Support staff: only their own shifts
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
        ]);

        Shift::create([
            ...$data,
            'status' => $data['status'] ?? 'scheduled',
            'created_by' => $auth->id,
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

        $shift->load(['client:id,first_name,last_name', 'staff:id,name,email']);
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
        ]);

        $shift->update($data);

        return redirect()->route('shifts.index')->with('success', 'Shift updated.');
    }
}
