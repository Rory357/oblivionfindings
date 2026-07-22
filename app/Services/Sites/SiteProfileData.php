<?php

namespace App\Services\Sites;

use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Sites\Profile\SiteProfileAdminPresenter;
use App\Services\Sites\Profile\SiteProfileOperationsPresenter;
use App\Services\Sites\Profile\SiteProfilePeoplePresenter;
use App\Services\Sites\Profile\SiteProfileSafetyPresenter;
use Illuminate\Database\Eloquent\Builder;

class SiteProfileData
{
    private const PINNED_TABS_KEY = 'sites.profile.pinned-tabs';

    public function __construct(
        private readonly SiteReadinessService $readiness,
        private readonly SiteProfileAttentionService $attention,
        private readonly SiteProfilePeoplePresenter $peoplePresenter,
        private readonly SiteProfileSafetyPresenter $safetyPresenter,
        private readonly SiteProfileOperationsPresenter $operationsPresenter,
        private readonly SiteProfileAdminPresenter $adminPresenter,
    ) {}

    /** @return array<string, mixed> */
    public function shell(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);
        $this->primeReadinessCounts($site);

        $site->loadMissing([
            'primaryContact:id,name',
            'managerContact:id,site_id,name,role,phone,email,is_primary',
            'siteLeadContact:id,site_id,name,role,phone,email,is_primary',
            'afterHoursContact:id,site_id,name,role,phone,email,is_primary',
            'primarySiteContact:id,site_id,name,role,phone,email,is_primary',
        ]);

        $permissions = $this->permissions($user, $site);
        $readiness = $this->readiness->evaluate($site);
        $attention = $this->attention->forSite($user, $site);
        $occupancy = $this->occupancy($site);

        return [
            'site' => $this->site($site),
            'hero' => $this->hero($user, $site, $permissions, $readiness, $attention, $occupancy),
            'permissions' => $permissions,
            'attention' => $attention,
            'overview' => $this->overview($user, $site),
            'readiness' => $readiness,
            'uiPreferences' => [
                'pinned_tabs' => $this->pinnedTabs($user),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function people(User $user, Site $site): array
    {
        return [
            'clients' => $this->peoplePresenter->clients($user, $site),
            'contacts' => $this->peoplePresenter->contacts($user, $site),
            'staff_requirements' => $this->peoplePresenter->staffRequirements($user, $site),
            'shift_coverage' => $this->peoplePresenter->shiftCoverage($user, $site),
        ];
    }

    /** @return array<string, mixed> */
    public function safety(User $user, Site $site): array
    {
        return [
            'locked' => ! $user->canDo('hazards.view'),
            'hazards' => $this->safetyPresenter->hazards($user, $site),
            'risk_assessments' => $this->safetyPresenter->riskAssessments($user, $site),
            'inspections' => $this->safetyPresenter->inspections($user, $site),
            'drills' => $this->safetyPresenter->drills($user, $site),
            'first_aid' => $this->safetyPresenter->firstAid($user, $site),
            'ppe' => $this->safetyPresenter->ppe($user, $site),
            'emergency_plan' => $this->safetyPresenter->emergencyPlan($user, $site),
        ];
    }

    /** @return array<string, mixed> */
    public function operations(User $user, Site $site): array
    {
        return [
            'calendar' => $this->operationsPresenter->calendar($user, $site),
            'checklists' => $this->operationsPresenter->checklists($user, $site),
            'meal_planner' => $this->operationsPresenter->mealPlanner($user, $site),
            'assets' => $this->operationsPresenter->assets($user, $site),
            'fleet' => $this->operationsPresenter->fleet($user, $site),
            'hardware' => $this->operationsPresenter->hardware($user, $site),
            'plan' => $this->operationsPresenter->plan($user, $site),
        ];
    }

    /** @return array<string, mixed> */
    public function admin(User $user, Site $site): array
    {
        return [
            'documents' => $this->adminPresenter->documents($user, $site),
            'financials' => $this->adminPresenter->financials($user, $site),
            'vendors_credentials' => $this->adminPresenter->vendorsCredentials($user, $site),
            'services' => $this->adminPresenter->services($user, $site),
        ];
    }

    /** @return array<string, mixed> */
    private function site(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'type' => $site->type,
            'display_type' => $site->display_type,
            'brand_colour' => $site->brand_colour,
            'phone' => $site->phone,
            'email' => $site->email,
            'is_active' => (bool) $site->is_active,
            'archived' => (bool) $site->archived,
            'address' => $site->address,
            'address_line_1' => $site->address_line_1,
            'address_line_2' => $site->address_line_2,
            'suburb' => $site->suburb,
            'city' => $site->city,
            'postcode' => $site->postcode,
            'country' => $site->country,
            'region' => $site->resolved_region,
            'latitude' => $site->latitude,
            'longitude' => $site->longitude,
            'access_instructions' => $site->access_instructions,
            'is_high_risk' => (bool) $site->is_high_risk,
            'is_high_needs' => (bool) $site->is_high_needs,
            'risk_notes' => $site->risk_notes,
            'risk_review_date' => $site->risk_review_date?->toDateString(),
            'emergency_plan_location' => $site->emergency_plan_location,
            'medication_storage_location' => $site->medication_storage_location,
            'notes' => $site->notes,
            'primary_contact' => $site->primaryContact?->only(['id', 'name']),
            'manager_contact' => $site->managerContact?->only(['id', 'name', 'role', 'phone', 'email', 'is_primary']),
            'site_lead_contact' => $site->siteLeadContact?->only(['id', 'name', 'role', 'phone', 'email', 'is_primary']),
            'after_hours_contact' => $site->afterHoursContact?->only(['id', 'name', 'role', 'phone', 'email', 'is_primary']),
            'primary_site_contact' => $site->primarySiteContact?->only(['id', 'name', 'role', 'phone', 'email', 'is_primary']),
        ];
    }

    /** @return array<string, mixed> */
    private function hero(
        User $user,
        Site $site,
        array $permissions,
        array $readiness,
        array $attention,
        array $occupancy,
    ): array {
        $avatars = $this->canViewClients($user) && $site->type !== 'head_office'
            ? $site->clients()
                ->whereIn('status', ['active', 'onboarding'])
                ->orderBy('first_name')
                ->limit(5)
                ->get(['id', 'first_name', 'last_name', 'preferred_name', 'profile_photo_path'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->full_name,
                    'profile_photo_url' => $client->profile_photo_url,
                ])->values()
            : collect();

        $quickActions = collect($site->archived ? [] : [
            $permissions['site.update'] ? [
                'id' => 'edit_site',
                'label' => 'Edit Site',
                'href' => route('sites.edit', $site),
            ] : null,
            $permissions['clients.create'] && $site->type !== 'head_office' ? [
                'id' => 'add_client',
                'label' => 'Add Client',
                'href' => route('clients.create', ['site_id' => $site->id]),
            ] : null,
            $permissions['calendar.create'] ? [
                'id' => 'add_calendar_event',
                'label' => 'Add Event',
                'href' => route('sites.calendar.index', [$site, 'action' => 'create']),
            ] : null,
            $permissions['hazards.create'] ? [
                'id' => 'report_hazard',
                'label' => 'Report Hazard',
                'href' => route('sites.hazards.create', $site),
            ] : null,
        ])->filter()->values();

        return [
            'eyebrow' => $site->display_type,
            'title' => $site->name,
            'description' => $site->address ?: 'Address not yet recorded',
            'brand_colour' => $site->brand_colour,
            'status' => $site->archived ? 'archived' : ($site->is_active ? 'active' : 'inactive'),
            'readiness' => [
                'score' => $readiness['score'],
                'missing_critical' => count($readiness['missing_critical']),
            ],
            'attention' => $attention['summary'],
            'occupancy' => $occupancy,
            'avatars' => $avatars,
            'quick_actions' => $quickActions,
        ];
    }

    /** @return array<string, mixed> */
    private function overview(User $user, Site $site): array
    {
        $contacts = $site->contacts()
            ->orderByDesc('is_primary')
            ->orderByRaw("FIELD(type, 'site_lead', 'manager', 'emergency', 'maintenance')")
            ->orderBy('id')
            ->limit(6)
            ->get(['id', 'type', 'name', 'role', 'phone', 'email', 'is_primary'])
            ->map(fn ($contact) => [
                'id' => $contact->id,
                'type' => $contact->type,
                'name' => $contact->name,
                'role' => $contact->role,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'is_primary' => (bool) $contact->is_primary,
            ])->values();

        $services = $site->serviceContexts()
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'type', 'description'])
            ->map(fn ($context) => $context->only(['id', 'name', 'type', 'description']))
            ->values();

        $notes = $site->siteNotes()
            ->with('createdBy:id,name')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get(['id', 'site_id', 'created_by_user_id', 'body', 'created_at'])
            ->map(fn ($note) => [
                'id' => $note->id,
                'body' => $note->body,
                'created_at' => $note->created_at?->toISOString(),
                'created_by' => $note->createdBy?->name,
            ])->values();

        $geofences = $site->geofences()
            ->with('assignedAssets:id')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn ($geofence) => [
                'id' => $geofence->id,
                'name' => $geofence->name,
                'type' => $geofence->type,
                'shape' => $geofence->shape,
                'breach_type' => $geofence->breach_type,
                'is_active' => (bool) $geofence->is_active,
                'asset_id' => $geofence->asset_id,
                'assigned_asset_ids' => $geofence->assignedAssets->pluck('id')->values(),
            ])->values();

        $geofenceAssets = $this->canViewAssets($user)
            ? Asset::query()
                ->where('site_id', $site->id)
                ->orderBy('name')
                ->get(['id', 'name', 'asset_tag', 'category', 'status'])
            : collect();

        return [
            'location' => [
                'address' => $site->address,
                'region' => $site->resolved_region,
                'latitude' => $site->latitude,
                'longitude' => $site->longitude,
                'access_instructions' => $site->access_instructions,
            ],
            'contacts' => $contacts,
            'safety' => [
                'is_high_risk' => (bool) $site->is_high_risk,
                'is_high_needs' => (bool) $site->is_high_needs,
                'risk_notes' => $site->risk_notes,
                'risk_review_date' => $site->risk_review_date?->toDateString(),
                'emergency_plan_location' => $site->emergency_plan_location,
                'medication_storage_location' => $site->medication_storage_location,
            ],
            'services' => $services,
            'notes' => $notes,
            'geofences' => $geofences,
            'geofence_assets' => $geofenceAssets,
            'can_manage' => ! $site->archived && $user->can('update', $site),
            'can_manage_geofences' => ! $site->archived && $user->canDo('assets.geofences.manage'),
        ];
    }

    /** @return array<string, mixed> */
    private function permissions(User $user, Site $site): array
    {
        $keys = [
            'sites.update', 'clients.viewAny', 'clients.viewAssigned', 'clients.create',
            'clients.assignments.update', 'staff.viewAny', 'rostering.viewAny',
            'hazards.view', 'hazards.create', 'calendar.view', 'calendar.create',
            'checklists.view', 'sites.meals.view', 'assets.viewAny', 'assets.viewAssigned',
            'fleet.viewAny', 'securityDevices.devices.view', 'finance.dashboard',
            'vendors.view', 'credentials.view', 'assets.geofences.manage',
        ];

        $permissions = [];
        foreach ($keys as $key) {
            $permissions[$key] = $user->canDo($key);
        }

        $permissions['site.update'] = $permissions['sites.update'] && $user->can('update', $site);

        return $permissions;
    }

    /** @return array<string, int|string> */
    private function occupancy(Site $site): array
    {
        return match ($site->type) {
            'house', 'residential' => [
                'label' => 'Bedrooms',
                'total' => (int) $site->getAttribute('rooms_total'),
                'occupied' => $site->houseRooms()
                    ->active()
                    ->where('is_assignable', true)
                    ->whereNotNull('assigned_client_id')
                    ->count(),
            ],
            'head_office' => [
                'label' => 'Resources',
                'total' => (int) $site->getAttribute('ho_resources_count'),
                'occupied' => 0,
            ],
            'facility' => [
                'label' => 'Places',
                'total' => (int) ($site->total_capacity ?: $site->getAttribute('facility_zones_count')),
                'occupied' => 0,
            ],
            default => [
                'label' => 'Spaces',
                'total' => (int) ($site->total_capacity ?? 0),
                'occupied' => 0,
            ],
        };
    }

    private function pinnedTabs(User $user): array
    {
        $preference = UserUiPreference::query()
            ->where('user_id', $user->id)
            ->where('key', self::PINNED_TABS_KEY)
            ->first(['value']);

        $value = $preference?->value ?? [];

        return collect(is_array($value) ? $value : [])
            ->filter(fn ($tab) => is_string($tab) && $tab !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function primeViewerPermissions(User $user): void
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
    }

    private function primeReadinessCounts(Site $site): void
    {
        $site->loadCount([
            'documents',
            'checklistAssignments',
            'houseRooms as rooms_total' => fn (Builder $query) => $query->active()->where('is_assignable', true),
            'hoResources as ho_resources_count' => fn (Builder $query) => $query->active(),
            'facilityZones as facility_zones_count' => fn (Builder $query) => $query->active(),
            'hazards as recent_hazards_count' => fn (Builder $query) => $query->where('updated_at', '>=', now()->subDays(90)),
            'hazards as open_hazards_count' => fn (Builder $query) => $query->whereIn('status', ['open', 'in_progress', 'reopened']),
            'geofences as active_geofences_count' => fn (Builder $query) => $query->where('is_active', true),
            'contacts as site_lead_contacts_count' => fn (Builder $query) => $query->whereIn('type', ['site_lead', 'manager']),
            'contacts as after_hours_contacts_count' => fn (Builder $query) => $query->where('type', 'emergency'),
            'contacts as emergency_contacts_count' => fn (Builder $query) => $query->whereIn('type', ['emergency', 'maintenance', 'manager']),
        ]);
    }

    private function canViewClients(User $user): bool
    {
        return $user->canDo('clients.viewAny') || $user->canDo('clients.viewAssigned');
    }

    private function canViewAssets(User $user): bool
    {
        return $user->canDo('assets.viewAny') || $user->canDo('assets.viewAssigned');
    }
}
