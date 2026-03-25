<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use Illuminate\Http\Request;

class JobBoardController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('job_board.viewAny'), 403);

        $positions = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'shift:id,client_id,site_id,starts_at,ends_at',
                'shift.client:id,first_name,last_name',
                'shift.site:id,name',
                'claimedBy:id,name',
                'approvedBy:id,name',
            ])
            ->where('status', 'open')
            ->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/job-board/Index', [
            'positions' => $positions,
        ]);
    }

    public function createPosition(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('job_board.create'), 403);

        $data = $request->validate([
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'requirements' => ['nullable', 'string'],
        ]);

        ShiftOpenPosition::create([
            'organization_id' => $auth->organization_id,
            'shift_id' => $data['shift_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'requirements' => $data['requirements'] ?? null,
            'status' => 'open',
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Position created.');
    }

    public function claim(Request $request, $position)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('job_board.claim'), 403);

        $position = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('status', 'open')
            ->findOrFail($position);

        $position->update([
            'claimed_by' => $auth->id,
            'claimed_at' => now(),
            'status' => 'claimed',
        ]);

        return redirect()->back()->with('success', 'Position claimed.');
    }

    public function approve(Request $request, $position)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('job_board.approve'), 403);

        $position = ShiftOpenPosition::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->where('status', 'claimed')
            ->findOrFail($position);

        $position->update([
            'approved_by' => $auth->id,
            'approved_at' => now(),
            'status' => 'filled',
        ]);

        // Assign the claiming staff to the shift
        if ($position->shift && $position->claimed_by) {
            $position->shift->update([
                'staff_id' => $position->claimed_by,
            ]);
        }

        return redirect()->back()->with('success', 'Claim approved and staff assigned.');
    }
}
