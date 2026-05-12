<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientOnboardingWorkflow;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SiteClientController extends Controller
{
    /**
     * Quick-create a new client and link them to this site. Uses the same
     * onboarding workflow as the canonical client store flow so newly created
     * clients show up in the onboarding pipeline.
     */
    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'preferred_name' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['active', 'inactive', 'onboarding'])],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'nhi_number' => Client::nhiValidationRules(),
            'risk_level' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
        ]);

        $auth = $request->user();

        $client = DB::transaction(function () use ($data, $site, $auth) {
            $payload = array_merge($data, [
                'site_id' => $site->id,
                'service_context_id' => $data['service_context_id'] ?? ServiceContext::defaultId(),
                'organization_id' => $auth?->organization_id,
            ]);
            if (empty($payload['risk_level'])) {
                $payload['risk_level'] = 'low';
            }

            $client = Client::create($payload);
            ClientOnboardingWorkflow::createForClient($client, $auth->id);

            return $client;
        });

        AuditLogger::log('sites.clients.create', $client, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($auth, 'created', 'client', $client, $client, [
            'title' => "Client created: {$client->first_name} {$client->last_name}",
            'url' => url("/clients/{$client->id}"),
        ]);

        return back()->with('success', 'Client created and linked to this site.');
    }

    /**
     * Link an existing (currently unassigned) client to this site.
     */
    public function link(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $client = Client::query()->findOrFail($data['client_id']);

        // Only allow linking from the same organisation, when known.
        $auth = $request->user();
        if ($auth?->organization_id && $client->organization_id && $client->organization_id !== $auth->organization_id) {
            abort(403, 'Client belongs to another organisation.');
        }

        $previousSiteId = $client->site_id;
        $client->site_id = $site->id;
        $client->save();

        AuditLogger::log('sites.clients.link', $client, [
            'site_id' => $site->id,
            'previous_site_id' => $previousSiteId,
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
    public function unlink(Request $request, Site $site, Client $client)
    {
        $this->authorize('update', $site);
        abort_unless($client->site_id === $site->id, 404);

        $client->site_id = null;
        $client->save();

        AuditLogger::log('sites.clients.unlink', $client, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client', $client, $client, [
            'title' => 'Client unlinked from site',
            'body' => "{$client->first_name} {$client->last_name}",
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Client unlinked from this site.');
    }
}
