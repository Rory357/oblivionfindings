<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Services\Assets\AssetAssignmentService;
use Illuminate\Http\Request;

class AssetAssignmentController extends Controller
{
    public function __construct(
        private readonly AssetAssignmentService $assignments,
    ) {}

    public function store(Request $request, Asset $asset)
    {
        abort_unless($request->user()?->canDo('assets.assignments.manage'), 403);

        $data = $request->validate([
            'assignee_type' => ['required', 'in:staff,client,whanau'],
            'assignee_id' => ['required', 'integer'],
            'purpose' => ['nullable', 'string'],
            'assigned_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $this->assignments->assign($request->user(), $asset, $data);

        return back()->with('success', 'Assignment created.');
    }

    public function release(Request $request, Asset $asset, AssetAssignment $assignment)
    {
        abort_unless($request->user()?->canDo('assets.assignments.manage'), 403);

        $this->assignments->release($request->user(), $asset, $assignment);

        return back()->with('success', 'Assignment released.');
    }
}
