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
use Illuminate\Validation\ValidationException;

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
        abort_unless($request->user()?->canDo('clients.create'), 403);

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
            'service_context_id' => [
                'nullable',
                'integer',
                Rule::exists('service_contexts', 'id')->where(
                    fn ($query) => $query->where(fn ($siteScope) => $siteScope
                        ->whereNull('site_id')
                        ->orWhere('site_id', $site->id))
                        ->where('is_active', true),
                ),
            ],
        ]);

        $auth = $request->user();

        $client = DB::transaction(function () use ($data, $site, $auth) {
            $serviceContextId = $data['service_context_id'] ?? ServiceContext::query()
                ->whereKey(ServiceContext::defaultId())
                ->where(fn ($query) => $query
                    ->whereNull('site_id')
                    ->orWhere('site_id', $site->id))
                ->value('id');

            $payload = array_merge($data, [
                'site_id' => $site->id,
                'service_context_id' => $serviceContextId,
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
        abort_unless(
            $request->user()?->canDo('clients.assignments.update')
                && $request->user()?->canDo('clients.viewAny'),
            403,
        );

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
        ]);

        $auth = $request->user();
        $client = DB::transaction(function () use ($data, $site): Client {
            $client = Client::query()->whereKey($data['client_id'])->lockForUpdate()->firstOrFail();
            if ($client->site_id !== null) {
                throw ValidationException::withMessages([
                    'client_id' => 'This client is already assigned to a Site. Move them from Client Profile.',
                ]);
            }

            $client->site_id = $site->id;
            $client->save();

            return $client;
        });

        AuditLogger::log('sites.clients.link', $client, [
            'site_id' => $site->id,
            'previous_site_id' => null,
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
        abort_unless($request->user()?->canDo('clients.assignments.update'), 403);

        $client = DB::transaction(function () use ($site, $client): Client {
            $client = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            abort_unless($client->site_id === $site->id, 404);

            $client->site_id = null;
            $client->save();

            return $client;
        });

        AuditLogger::log('sites.clients.unlink', $client, ['site_id' => $site->id]);

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'client', $client, $client, [
            'title' => 'Client unlinked from site',
            'body' => "{$client->first_name} {$client->last_name}",
            'url' => url("/sites/{$site->id}"),
        ]);

        return back()->with('success', 'Client unlinked from this site.');
    }
}
