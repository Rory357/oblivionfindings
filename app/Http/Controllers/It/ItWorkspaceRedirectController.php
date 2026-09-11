<?php

namespace App\Http\Controllers\It;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stable navigation destinations while later packages extend the canonical workspaces. */
final class ItWorkspaceRedirectController extends Controller
{
    public function knowledge(Request $request): RedirectResponse
    {
        return redirect()->route('it.index', [...$request->query(), 'tab' => 'knowledge']);
    }

    public function reports(Request $request): RedirectResponse
    {
        return redirect()->route('it.index', [...$request->query(), 'tab' => 'reports']);
    }

    public function work(Request $request): RedirectResponse
    {
        return redirect()->route('it.index', [...$request->query(), 'tab' => 'tickets', 'view' => 'mine']);
    }
}
