<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $clients = $user->portalClients()->get(['clients.id', 'clients.first_name', 'clients.last_name']);

        return inertia('portal/index', [
            'clients' => $clients->map(fn($c) => [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'relation' => $c->pivot->relation,
            ])->values(),
        ]);
    }
}
