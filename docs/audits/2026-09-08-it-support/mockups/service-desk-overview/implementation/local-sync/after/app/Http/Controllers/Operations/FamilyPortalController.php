<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FamilyPortalSetting;
use App\Services\Portal\PortalClientSectionAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FamilyPortalController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    /** Maps the "sharing" filter values to family_portal_settings columns. */
    private const SHARING_COLUMNS = [
        'shift_schedule' => 'show_shift_schedule',
        'respite' => 'show_respite',
        'care_notes' => 'show_care_notes',
        'incidents' => 'show_incidents',
    ];

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canViewPortal($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'portal' => ['nullable', 'in:active,inactive'],
            'sharing' => ['nullable', 'in:shift_schedule,respite,care_notes,incidents'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $base = fn () => $this->siteAccess->applyClientScope(Client::query(), $auth, ['clients.viewAny']);

        $clients = $base()
            ->with(['familyPortalSetting'])
            ->withCount([
                'portalUsers as family_contacts_count' => fn ($q) => $q
                    ->where('client_portal_users.relation', '!=', 'client'),
            ])
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $like = '%'.$search.'%';
                $q2->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like);
            }))
            ->when(($filters['portal'] ?? null) === 'active', fn ($q) => $q->whereHas('familyPortalSetting'))
            ->when(($filters['portal'] ?? null) === 'inactive', fn ($q) => $q->whereDoesntHave('familyPortalSetting'))
            ->when($filters['sharing'] ?? null, fn ($q, $sharing) => $q->whereHas(
                'familyPortalSetting',
                fn ($s) => $s->where(self::SHARING_COLUMNS[$sharing], true),
            ))
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
                // Only flags with a real settings column — no decorative
                // always-off badges (DESIGN.md no-fake-data rule).
                'notifications' => [
                    'shift_updates' => (bool) ($setting?->show_shift_schedule ?? false),
                    'respite' => (bool) ($setting?->show_respite ?? false),
                    'care_notes' => (bool) ($setting?->show_care_notes ?? false),
                    'incident_alerts' => (bool) ($setting?->show_incidents ?? false),
                ],
                'family_contacts_count' => (int) $client->family_contacts_count,
            ];
        });

        return inertia('operations/family-portal/Index', [
            'clients' => $clients,
            'filters' => [
                'q' => $filters['q'] ?? null,
                'portal' => $filters['portal'] ?? null,
                'sharing' => $filters['sharing'] ?? null,
            ],
            // Header instruments — counted over the whole accessible set
            // regardless of the active filters so rail counts stay honest.
            'stats' => [
                'total' => $base()->count(),
                'enabled' => $base()->whereHas('familyPortalSetting')->count(),
                'family_contacts' => DB::table('client_portal_users')
                    ->where('relation', '!=', 'client')
                    ->whereIn('client_id', $base()->select('clients.id'))
                    ->count(),
            ],
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
