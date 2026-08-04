<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Services\Portal\PortalClientSectionAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;

class FamilyPortalController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewPortal($auth), 403);

        $clients = $this->siteAccess->applyClientScope(Client::query(), $auth, ['clients.viewAny'])
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
                    'respite' => $setting?->show_respite ?? false,
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
        abort_unless($auth && $this->canViewPortal($auth), 403);

        $client = $this->siteAccess->applyClientScope(Client::query(), $auth, ['clients.viewAny'])
            ->with(['familyPortalSetting'])
            ->findOrFail($client);

        return inertia('operations/family-portal/Show', [
            'client' => $client,
        ]);
    }

    public function edit(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManagePortal($auth), 403);

        $client = $this->siteAccess->applyClientScope(Client::query(), $auth, ['clients.viewAny'])
            ->with(['familyPortalSetting'])
            ->findOrFail($client);

        return inertia('operations/family-portal/Edit', [
            'client' => $client,
        ]);
    }

    public function update(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canManagePortal($auth), 403);

        $clientModel = $this->siteAccess->applyClientScope(Client::query(), $auth, ['clients.viewAny'])
            ->findOrFail($client);

        $data = $request->validate([
            'show_shift_schedule' => ['nullable', 'boolean'],
            'show_respite' => ['nullable', 'boolean'],
            'show_care_notes' => ['nullable', 'boolean'],
            'show_care_plans' => ['nullable', 'boolean'],
            'show_medication_status' => ['nullable', 'boolean'],
            'show_incidents' => ['nullable', 'boolean'],
            'notify_shift_arrival' => ['nullable', 'boolean'],
            'notify_shift_completion' => ['nullable', 'boolean'],
            'notify_incident' => ['nullable', 'boolean'],
        ]);

        $hasFamilyInformationConsent = app(PortalClientSectionAccess::class)
            ->hasActiveFamilyInformationConsent($clientModel);
        $showRespite = $data['show_respite'] ?? true;
        $showCareNotes = $data['show_care_notes'] ?? true;
        $showIncidents = $data['show_incidents'] ?? false;

        if (! $hasFamilyInformationConsent) {
            $showRespite = false;
            $showCareNotes = false;
            $showIncidents = false;
        }

        FamilyPortalSetting::updateOrCreate(
            ['client_id' => $client],
            [
                'show_shift_schedule' => $data['show_shift_schedule'] ?? true,
                'show_respite' => $showRespite,
                'show_care_notes' => $showCareNotes,
                'show_care_plans' => $data['show_care_plans'] ?? false,
                'show_medication_status' => $data['show_medication_status'] ?? false,
                'show_incidents' => $showIncidents,
                'notify_shift_arrival' => $data['notify_shift_arrival'] ?? true,
                'notify_shift_completion' => $data['notify_shift_completion'] ?? true,
                'notify_incident' => $data['notify_incident'] ?? true,
            ]
        );

        $message = $hasFamilyInformationConsent
            ? 'Portal settings updated.'
            : 'Portal settings updated. Information-sharing surfaces remain off until active family information consent is recorded.';

        return redirect()->back()->with('success', $message);
    }

    private function canViewPortal($auth): bool
    {
        return $auth->canDo('family_portal.viewAny')
            || $auth->canDo('clients.update');
    }

    private function canManagePortal($auth): bool
    {
        return $auth->canDo('family_portal.manage')
            || $auth->canDo('clients.update');
    }
}
