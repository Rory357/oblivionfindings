<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class QualityChecklistController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('reports.viewAny') || $user->canDo('audit.viewAny') || $user->canDo('settings.access.manage')), 403);

        return inertia('quality/checklist');
    }
}
