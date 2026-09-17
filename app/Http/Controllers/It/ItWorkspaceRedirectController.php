<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Compatibility entry point until the dedicated work-planning workspace lands. */
final class ItWorkspaceRedirectController extends Controller
{
    public function work(Request $request): RedirectResponse
    {
        return redirect()->route('it.index', [...$request->query(), 'tab' => 'tickets', 'view' => 'mine']);
    }
}
