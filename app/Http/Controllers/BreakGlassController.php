<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientBreakGlassAccess;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class BreakGlassController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('medications.breakglass'), 403);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        // Default: expire after 60 minutes unless explicitly set.
        $minutes = !empty($data['minutes']) ? (int) $data['minutes'] : 60;
        $expiresAt = now()->addMinutes($minutes);

        $access = ClientBreakGlassAccess::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'expires_at' => $expiresAt,
        ]);

        app(NotificationService::class)->notifyCrud($user, 'created', 'break-glass access', $access, $client, [
            'title' => 'Break-glass access used',
            'url' => url("/clients/{$client->id}"),
        ]);

        return back()->with('success', 'Break-glass access granted.');
    }

    public function destroy(Request $request, Client $client, ClientBreakGlassAccess $access)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('medications.breakglass') || $user->canDo('medications.audit.view')), 403);

        abort_unless((int) $access->client_id === (int) $client->id, 404);

        $isManager = $user->hasRole('admin', 'provider_manager') || $user->canDo('medications.audit.view');
        $isOwner = (int) $access->user_id === (int) $user->id;
        abort_unless($isManager || $isOwner, 403);

        $access->delete();

        app(NotificationService::class)->notifyCrud($user, 'deleted', 'break-glass access', $access, $client, [
            'title' => 'Break-glass access revoked',
            'url' => url("/clients/{$client->id}/mar"),
        ]);

        return back()->with('success', 'Break-glass access revoked.');
    }
}
