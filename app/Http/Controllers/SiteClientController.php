<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sites\LinkSiteClientRequest;
use App\Models\Client;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Sites\SiteClientPlacementService;
use Illuminate\Http\Request;

class SiteClientController extends Controller
{
    /**
     * Link an existing (currently unassigned) client to this site.
     */
    public function link(
        LinkSiteClientRequest $request,
        Site $site,
        SiteClientPlacementService $placement,
    ) {
        $auth = $request->user();
        $client = $placement->place($site, $request->validated(), $auth);

        AuditLogger::log('sites.clients.link', $client, [
            'site_id' => $site->id,
            'room_id' => $client->room_id,
            'service_context_id' => $client->service_context_id,
            'key_worker_id' => $client->key_worker_id,
        ]);

        app(NotificationService::class)->notifyCrud($auth, 'updated', 'client', $client, $client, [
            'title' => 'Client linked to site',
            'body' => "{$client->first_name} {$client->last_name} → {$site->name}",
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Client linked to this site.');
    }

    /**
     * Remove the link between this client and this site. Does NOT delete the
     * client record.
     */
    public function unlink(
        Request $request,
        Site $site,
        Client $client,
        SiteClientPlacementService $placement,
    ) {
        $this->authorize('update', $site);
        abort_unless($client->site_id === $site->id, 404);

        $client = $placement->unlink($site, $client, $request->user());

        AuditLogger::log('sites.clients.unlink', $client, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client', $client, $client, [
            'title' => 'Client unlinked from site',
            'body' => "{$client->first_name} {$client->last_name}",
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Client unlinked from this site.');
    }
}
