<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MedicationsController extends Controller
{
    /**
     * The legacy "Daily overview" page (/emar/daily) has been merged into the
     * single eMAR home at /emar (route emar.index). Keep the route name
     * resolvable so existing links don't 404 — permanently redirect to it.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.view'), 403);

        return redirect()->route('emar.index', $request->only('date'), 301);
    }
}
