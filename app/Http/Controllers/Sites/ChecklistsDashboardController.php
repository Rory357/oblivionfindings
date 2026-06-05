<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Support\ChecklistsDashboardData;
use Illuminate\Http\Request;

class ChecklistsDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user?->canDo('checklists.view')
                && ($user->canDo('checklists.schedule') || $user->canDo('checklists.manage_templates')),
            403,
        );

        return inertia('checklists/index', (new ChecklistsDashboardData($request))->forOrg());
    }
}
