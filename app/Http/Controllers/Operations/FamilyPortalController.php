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

        return inertia('operations/family-portal/Index', [
            'clients' => $clients,
        ]);
    }

    public function show(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.view'), 403);

        $client = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['familyPortalSetting'])
            ->findOrFail($client);

        return inertia('operations/family-portal/Show', [
            'client' => $client,
        ]);
    }

    public function update(Request $request, $client)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('family_portal.edit'), 403);

        Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($client);

        $data = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'can_view_schedule' => ['nullable', 'boolean'],
            'can_view_care_notes' => ['nullable', 'boolean'],
            'can_view_medications' => ['nullable', 'boolean'],
            'can_send_messages' => ['nullable', 'boolean'],
            'portal_contacts' => ['nullable', 'array'],
            'portal_contacts.*.name' => ['required', 'string', 'max:255'],
            'portal_contacts.*.email' => ['required', 'email', 'max:255'],
            'portal_contacts.*.relationship' => ['nullable', 'string', 'max:100'],
        ]);

        FamilyPortalSetting::updateOrCreate(
            ['client_id' => $client],
            [
                'is_enabled' => $data['is_enabled'],
                'can_view_schedule' => $data['can_view_schedule'] ?? false,
                'can_view_care_notes' => $data['can_view_care_notes'] ?? false,
                'can_view_medications' => $data['can_view_medications'] ?? false,
                'can_send_messages' => $data['can_send_messages'] ?? false,
                'portal_contacts' => $data['portal_contacts'] ?? null,
            ]
        );

        return redirect()->back()->with('success', 'Portal settings updated.');
    }
}
