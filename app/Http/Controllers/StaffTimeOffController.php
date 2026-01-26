<?php

namespace App\Http\Controllers;

use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Http\Request;

class StaffTimeOffController extends Controller
{
    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'type' => ['required', 'in:leave,unavailable,training'],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'return_to' => ['nullable', 'string'],
        ]);

        $userId = $data['user_id'] ?? $auth->id;
        if ($userId !== $auth->id && !$auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        abort_unless(User::staff()->whereKey($userId)->exists(), 404);

        StaffTimeOff::create([
            'user_id' => $userId,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'type' => $data['type'],
            'label' => $data['label'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $auth->id,
        ]);

        return redirect($data['return_to'] ?? url('/rostering'))
            ->with('success', 'Time off saved.');
    }

    public function destroy(Request $request, StaffTimeOff $staffTimeOff)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        if ($staffTimeOff->user_id !== $auth->id && !$auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        $returnTo = $request->input('return_to') ?: url('/rostering');
        $staffTimeOff->delete();

        return redirect($returnTo)->with('success', 'Time off deleted.');
    }
}
