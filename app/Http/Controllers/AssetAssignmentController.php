<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class AssetAssignmentController extends Controller
{
    public function store(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);
        abort_unless($request->user()?->canDo('assets.assignments.manage'), 403);

        $data = $request->validate([
            'assignee_type' => ['required', 'in:staff,client,whanau'],
            'assignee_id' => ['required', 'integer'],
            'purpose' => ['nullable', 'string'],
            'assigned_at' => ['nullable', 'date'],
        ]);

        $active = AssetAssignment::query()
            ->where('asset_id', $asset->id)
            ->where('assignee_type', $data['assignee_type'])
            ->where('assignee_id', $data['assignee_id'])
            ->whereNull('released_at')
            ->first();

        if ($active) {
            return back()->withErrors(['assignee_id' => 'Assignment already active.']);
        }

        $assignment = AssetAssignment::create([
            'asset_id' => $asset->id,
            'assignee_type' => $data['assignee_type'],
            'assignee_id' => $data['assignee_id'],
            'purpose' => $data['purpose'] ?? null,
            'assigned_at' => $data['assigned_at'] ?? now(),
        ]);

        AuditLogger::log('assets.assignment.created', $asset, [
            'assignment_id' => $assignment->id,
        ]);

        return back()->with('success', 'Assignment created.');
    }

    public function release(Request $request, Asset $asset, AssetAssignment $assignment)
    {
        $this->authorize('view', $asset);
        abort_unless($request->user()?->canDo('assets.assignments.manage'), 403);

        if ($assignment->asset_id !== $asset->id) {
            abort(404);
        }

        $assignment->update([
            'released_at' => now(),
        ]);

        AuditLogger::log('assets.assignment.released', $asset, [
            'assignment_id' => $assignment->id,
        ]);

        return back()->with('success', 'Assignment released.');
    }
}
