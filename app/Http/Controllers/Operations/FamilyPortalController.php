<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use Illuminate\Http\Request;

class FamilyPortalController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.viewAny'), 403);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['familyPortalSetting'])
            ->orderBy('first_name')
            ->paginate(20)
            ->withQueryString();

        $clients->through(function (Client $client) {
            $setting = $client->familyPortalSetting;

            return [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'portal_enabled' => $setting !== null,
                'notifications' => [
                    'shift_updates' => $setting?->show_shift_schedule ?? false,
                    'care_notes' => $setting?->show_care_notes ?? false,
                    'incident_alerts' => $setting?->show_incidents ?? false,
                    'billing_updates' => false,
                    'messages' => false,
                ],
                'family_contacts_count' => 0,
            ];
        });

        return inertia('operations/family-portal/Index', [
            'clients' => $clients,
        ]);
    }

    public function show(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.viewAny'), 403);

        $client = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['familyPortalSetting'])
            ->findOrFail($client);

        return inertia('operations/family-portal/Show', [
            'client' => $client,
        ]);
    }

    public function edit(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.manage'), 403);

        $client = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['familyPortalSetting'])
            ->findOrFail($client);

        return inertia('operations/family-portal/Edit', [
            'client' => $client,
        ]);
    }

    public function update(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.manage'), 403);

        Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($client);

        $data = $request->validate([
            'show_shift_schedule' => ['nullable', 'boolean'],
            'show_care_notes' => ['nullable', 'boolean'],
            'show_care_plans' => ['nullable', 'boolean'],
            'show_medication_status' => ['nullable', 'boolean'],
            'show_incidents' => ['nullable', 'boolean'],
            'notify_shift_arrival' => ['nullable', 'boolean'],
            'notify_shift_completion' => ['nullable', 'boolean'],
            'notify_incident' => ['nullable', 'boolean'],
        ]);

        FamilyPortalSetting::updateOrCreate(
            ['client_id' => $client],
            [
                'organization_id' => $auth->organization_id,
                'show_shift_schedule' => $data['show_shift_schedule'] ?? true,
                'show_care_notes' => $data['show_care_notes'] ?? true,
                'show_care_plans' => $data['show_care_plans'] ?? false,
                'show_medication_status' => $data['show_medication_status'] ?? false,
                'show_incidents' => $data['show_incidents'] ?? false,
                'notify_shift_arrival' => $data['notify_shift_arrival'] ?? true,
                'notify_shift_completion' => $data['notify_shift_completion'] ?? true,
                'notify_incident' => $data['notify_incident'] ?? true,
            ]
        );

        return redirect()->back()->with('success', 'Portal settings updated.');
    }
}
