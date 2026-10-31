<?php

namespace App\Http\Controllers;

use App\Services\Clients\ClientPortalMembershipService;
use Illuminate\Http\Request;

class PortalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // A current or former staff member is never listed as a portal member (same rule as the sidebar).
        $clients = app(ClientPortalMembershipService::class)->treatsAsPortalMember($user)
            ? $user->portalClients()->get(['clients.id', 'clients.first_name', 'clients.last_name'])
            : collect();

        return inertia('portal/index', [
            'clients' => $clients->map(fn($c) => [
                'id' => $c->id,
                'name' => trim($c->first_name . ' ' . $c->last_name),
                'relation' => $c->pivot->relation,
            ])->values(),
        ]);
    }
}
