<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Support\ChecklistsDashboardData;
use Illuminate\Http\Request;

class ChecklistsDashboardController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('checklists.view'), 403);

        return inertia('checklists/index', (new ChecklistsDashboardData($request))->forOrg());
    }
}
