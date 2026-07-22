<?php

namespace App\Services\Sites\Profile;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use App\Services\Clients\ClientFormOptions;
use App\Services\Clients\ClientWorkerEligibility;
use App\Services\ShiftCoverageService;
use Illuminate\Database\Eloquent\Builder;

class SiteProfilePeoplePresenter
{
    public function __construct(
        private readonly ShiftCoverageService $coverage,
        private readonly ClientFormOptions $clientFormOptions,
        private readonly ClientWorkerEligibility $clientWorkers,
    ) {}

    /** @return array<string, mixed> */
    public function clients(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $this->canViewClients($user) && $site->type !== 'head_office';
        $canPlace = ! $site->archived && $canView && $user->canDo('clients.assignments.update');
        $canCreate = ! $site->archived && $canView && $user->canDo('clients.create');
        $options = ($canCreate || $canPlace)
            ? $this->clientFormOptions->forOrganization($site->tenant_id ?? $user->organization_id)
            : null;

        $clients = $canView
            ? $site->clients()
                ->with(['keyWorker:id,name', 'serviceContext:id,name,type', 'room:id,name'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get([
                    'id', 'site_id', 'first_name', 'last_name', 'preferred_name',
                    'status', 'profile_photo_path', 'risk_level', 'safeguarding_flag',
                    'service_start_date', 'service_context_id', 'key_worker_id', 'room_id',
                ])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'name' => $client->full_name,
                    'preferred_name' => $client->preferred_name,
                    'status' => $client->status,
                    'profile_photo_url' => $client->profile_photo_url,
                    'risk_level' => $client->risk_level,
                    'safeguarding_flag' => (bool) $client->safeguarding_flag,
                    'service_start_date' => $client->service_start_date?->toDateString(),
                    'key_worker' => $client->keyWorker?->only(['id', 'name']),
                    'service_context' => $client->serviceContext?->only(['id', 'name', 'type']),
                    'room' => $client->room?->only(['id', 'name']),
                    'href' => route('clients.show', $client),
                ])->values()
            : collect();

        $available = $canPlace
            ? Client::query()
                ->whereNull('site_id')
                ->when($user->organization_id, fn (Builder $query, int $organizationId) => $query->where('organization_id', $organizationId))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get(['id', 'first_name', 'last_name', 'preferred_name', 'status'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'first_name' => $client->first_name,
                    'last_name' => $client->last_name,
                    'name' => $client->full_name,
                    'preferred_name' => $client->preferred_name,
                    'status' => $client->status,
                ])->values()
            : collect();

        return [
            'locked' => ! $canView,
            'items' => $clients,
            'summary' => $canView ? [
                'total' => $clients->count(),
                'active' => $clients->where('status', 'active')->count(),
                'onboarding' => $clients->where('status', 'onboarding')->count(),
                'high_risk' => $clients->where('risk_level', 'high')->count(),
                'safeguarding' => $clients->where('safeguarding_flag', true)->count(),
            ] : null,
            'available' => $available,
            'can_create' => $canCreate,
            'can_place_existing' => $canPlace,
            'create_options' => $canCreate ? $options : null,
            'placement_options' => $canPlace ? [
                'rooms' => $site->houseRooms()->available()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'notes']),
                'service_contexts' => ServiceContext::query()
                    ->forOrganization($site->tenant_id ?? $user->organization_id)
                    ->where(fn (Builder $query) => $query->whereNull('site_id')->orWhere('site_id', $site->id))
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'type']),
                'key_workers' => $this->clientWorkers
                    ->queryForOrganization($site->tenant_id ?? $user->organization_id)
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public function contacts(User $user, Site $site): array
    {
        return [
            'locked' => false,
            'items' => $site->contacts()
                ->orderByDesc('is_primary')
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'type', 'name', 'role', 'phone', 'email', 'is_primary', 'notes'])
                ->map(fn ($contact) => [
                    'id' => $contact->id,
                    'type' => $contact->type,
                    'name' => $contact->name,
                    'role' => $contact->role,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'is_primary' => (bool) $contact->is_primary,
                    'notes' => $contact->notes,
                ])->values(),
            'can_manage' => ! $site->archived && $user->can('update', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function staffRequirements(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('staff.viewAny');

        return [
            'locked' => ! $canView,
            'items' => $canView
                ? SiteStaffRequirement::query()
                    ->where('site_id', $site->id)
                    ->active()
                    ->orderBy('category')
                    ->orderBy('requirement_name')
                    ->limit(100)
                    ->get()
                    ->map(fn (SiteStaffRequirement $requirement) => [
                        'id' => $requirement->id,
                        'name' => $requirement->requirement_name,
                        'category' => $requirement->category,
                        'description' => $requirement->description,
                        'certification_required' => (bool) $requirement->certification_required,
                        'expiry_period_months' => $requirement->expiry_period_months,
                    ])->values()
                : collect(),
            'can_manage' => ! $site->archived && $canView && $user->can('update', $site),
        ];
    }

    /** @return array<string, mixed> */
    public function shiftCoverage(User $user, Site $site): array
    {
        $this->primePermissions($user);
        $canView = $user->canDo('rostering.viewAny') && $site->type !== 'head_office';

        $requirements = $canView
            ? SiteCoverageRequirement::query()
                ->where('site_id', $site->id)
                ->active()
                ->with(['serviceContext:id,name,type', 'preferredClient:id,first_name,last_name'])
                ->orderBy('day_of_week')
                ->orderBy('starts_time')
                ->get()
                ->map(fn (SiteCoverageRequirement $requirement) => [
                    'id' => $requirement->id,
                    'name' => $requirement->name,
                    'coverage_type' => $requirement->coverage_type,
                    'day_of_week' => $requirement->day_of_week,
                    'starts_time' => substr((string) $requirement->starts_time, 0, 5),
                    'ends_time' => substr((string) $requirement->ends_time, 0, 5),
                    'minimum_staff' => $requirement->minimum_staff,
                    'service_context_id' => $requirement->service_context_id,
                    'service_context' => $requirement->serviceContext?->only(['id', 'name', 'type']),
                    'preferred_client_id' => $requirement->preferred_client_id,
                    'preferred_client' => $requirement->preferredClient ? [
                        'id' => $requirement->preferredClient->id,
                        'name' => $requirement->preferredClient->full_name,
                    ] : null,
                    'role_requirements' => $requirement->role_requirements ?? [],
                    'allow_overstaffing' => (bool) $requirement->allow_overstaffing,
                    'shift_type' => $requirement->shift_type,
                    'notes' => $requirement->notes,
                ])->values()
            : collect();

        return [
            'locked' => ! $canView,
            'preview' => $canView
                ? $this->coverage->buildSiteSummaries(now()->startOfWeek(), now()->addWeek()->endOfWeek(), $site->id)
                : null,
            'requirements' => $requirements,
            'clients' => $canView
                ? $site->clients()
                    ->whereIn('status', ['active', 'onboarding'])
                    ->orderBy('first_name')
                    ->get(['id', 'first_name', 'last_name', 'preferred_name'])
                    ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->full_name])
                : collect(),
            'service_contexts' => $canView
                ? $site->serviceContexts()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])
                : collect(),
            'can_manage' => $canView && ! $site->archived && $user->can('update', $site),
            'href' => $canView ? route('operations.rostering.index', ['site_id' => $site->id]) : null,
        ];
    }

    private function primePermissions(User $user): void
    {
        $user->loadMissing(['roles.permissions', 'permissionOverrides']);
    }

    private function canViewClients(User $user): bool
    {
        return $user->canDo('clients.viewAny') || $user->canDo('clients.viewAssigned');
    }
}
