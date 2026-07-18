<?php

namespace App\Services\Sites;

use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\EmergencyDrill;
use App\Models\FirstAidRecord;
use App\Models\HsRiskAssessment;
use App\Models\PpeInventory;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteChecklistRun;
use App\Models\SiteCredential;
use App\Models\SiteDocument;
use App\Models\SiteDocumentFolder;
use App\Models\SiteHazard;
use App\Models\SiteInspectionSchedule;
use App\Models\SiteStaffRequirement;
use App\Models\SiteVendor;
use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\ShiftCoverageService;
use Illuminate\Database\Eloquent\Builder;

class SiteProfileData
{
    private const PINNED_TABS_KEY = 'sites.profile.pinned-tabs';

    public function __construct(
        private readonly SiteReadinessService $readiness,
        private readonly SiteProfileAttentionService $attention,
        private readonly SiteTypePlanService $typePlans,
        private readonly ShiftCoverageService $coverage,
        private readonly DeviceRegistryService $devices,
    ) {}

    /** @return array<string, mixed> */
    public function shell(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);
        $this->primeReadinessCounts($site);

        $site->loadMissing('primaryContact:id,name');

        $permissions = $this->permissions($user, $site);
        $readiness = $this->readiness->evaluate($site);
        $attention = $this->attention->forSite($user, $site);
        $occupancy = $this->occupancy($site);

        return [
            'site' => $this->site($site),
            'hero' => $this->hero($user, $site, $permissions, $readiness, $attention, $occupancy),
            'permissions' => $permissions,
            'attention' => $attention,
            'overview' => $this->overview($site),
            'readiness' => $readiness,
            'uiPreferences' => [
                'pinned_tabs' => $this->pinnedTabs($user),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function people(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);
        $canViewClients = $this->canViewClients($user) && $site->type !== 'head_office';
        $canPlaceClients = $canViewClients && $user->canDo('clients.assignments.update');
        $canViewStaff = $user->canDo('staff.viewAny');
        $canViewCoverage = $user->canDo('rostering.viewAny') && $site->type !== 'head_office';

        $clients = $canViewClients
            ? $site->clients()
                ->with(['keyWorker:id,name', 'serviceContext:id,name,type'])
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get([
                    'id', 'site_id', 'first_name', 'last_name', 'preferred_name',
                    'status', 'profile_photo_path', 'risk_level', 'safeguarding_flag',
                    'service_start_date', 'service_context_id', 'key_worker_id',
                ])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->full_name,
                    'preferred_name' => $client->preferred_name,
                    'status' => $client->status,
                    'profile_photo_url' => $client->profile_photo_url,
                    'risk_level' => $client->risk_level,
                    'safeguarding_flag' => (bool) $client->safeguarding_flag,
                    'service_start_date' => $client->service_start_date?->toDateString(),
                    'key_worker' => $client->keyWorker?->only(['id', 'name']),
                    'service_context' => $client->serviceContext?->only(['id', 'name', 'type']),
                    'href' => route('clients.show', $client),
                ])->values()
            : collect();

        $availableClients = $canPlaceClients
            ? Client::query()
                ->whereNull('site_id')
                ->when($user->organization_id, fn (Builder $query, int $organizationId) => $query->where('organization_id', $organizationId))
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get(['id', 'first_name', 'last_name', 'preferred_name', 'status'])
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => $client->full_name,
                    'preferred_name' => $client->preferred_name,
                    'status' => $client->status,
                ])->values()
            : collect();

        $contacts = $site->contacts()
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
            ])->values();

        $staffRequirements = $canViewStaff
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
            : collect();

        return [
            'clients' => [
                'locked' => ! $canViewClients,
                'items' => $clients,
                'summary' => $canViewClients ? [
                    'total' => $clients->count(),
                    'active' => $clients->where('status', 'active')->count(),
                    'onboarding' => $clients->where('status', 'onboarding')->count(),
                    'high_risk' => $clients->where('risk_level', 'high')->count(),
                    'safeguarding' => $clients->where('safeguarding_flag', true)->count(),
                ] : null,
                'available' => $availableClients,
                'create_href' => $user->canDo('clients.create')
                    ? route('clients.create', ['site_id' => $site->id])
                    : null,
                'can_place_existing' => $canPlaceClients,
            ],
            'contacts' => [
                'items' => $contacts,
                'can_manage' => $user->canDo('sites.update'),
            ],
            'staff_requirements' => [
                'locked' => ! $canViewStaff,
                'items' => $staffRequirements,
                'can_manage' => $canViewStaff && $user->canDo('sites.update'),
            ],
            'shift_coverage' => [
                'locked' => ! $canViewCoverage,
                'summary' => $canViewCoverage
                    ? $this->coverage->buildSiteSummaries(now()->startOfWeek(), now()->addWeek()->endOfWeek(), $site->id)
                    : [],
                'href' => $canViewCoverage ? route('operations.rostering.index', ['site_id' => $site->id]) : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function safety(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);
        $canView = $user->canDo('hazards.view');

        if (! $canView) {
            return [
                'locked' => true,
                'hazards' => ['items' => [], 'summary' => null],
                'risk_assessments' => ['items' => [], 'summary' => null],
                'inspections' => ['items' => [], 'summary' => null],
                'drills' => ['items' => [], 'summary' => null],
                'first_aid' => ['items' => [], 'summary' => null],
                'ppe' => ['items' => [], 'summary' => null],
                'emergency_plan' => ['summary' => null],
            ];
        }

        $hazards = SiteHazard::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['open', 'in_progress', 'reopened'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'reference_number', 'description', 'severity', 'risk_rating', 'status', 'due_date', 'review_date'])
            ->map(fn (SiteHazard $hazard) => [
                'id' => $hazard->id,
                'reference' => $hazard->reference_number,
                'description' => $hazard->description,
                'severity' => $hazard->severity,
                'risk_rating' => $hazard->risk_rating,
                'status' => $hazard->status,
                'due_date' => $hazard->due_date?->toDateString(),
                'review_date' => $hazard->review_date?->toDateString(),
                'href' => route('sites.hazards.show', $hazard),
            ])->values();

        $riskAssessments = HsRiskAssessment::query()
            ->forAssessable(Site::class, $site->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['id', 'reference_number', 'title', 'status', 'risk_level', 'review_due_at'])
            ->map(fn (HsRiskAssessment $assessment) => [
                'id' => $assessment->id,
                'reference' => $assessment->reference_number,
                'title' => $assessment->title,
                'status' => $assessment->status,
                'risk_level' => $assessment->risk_level,
                'review_due_at' => $assessment->review_due_at?->toDateString(),
            ])->values();

        $inspections = SiteInspectionSchedule::query()
            ->where('site_id', $site->id)
            ->active()
            ->orderBy('next_due_date')
            ->limit(20)
            ->get(['id', 'title', 'inspection_type', 'frequency', 'next_due_date'])
            ->map(fn (SiteInspectionSchedule $schedule) => [
                'id' => $schedule->id,
                'title' => $schedule->title,
                'type' => $schedule->inspection_type,
                'frequency' => $schedule->frequency,
                'next_due_date' => $schedule->next_due_date?->toDateString(),
                'overdue' => $schedule->next_due_date?->isPast() ?? false,
            ])->values();

        $drills = EmergencyDrill::query()
            ->where('site_id', $site->id)
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get(['id', 'title', 'drill_type', 'scheduled_at', 'completed_at', 'status', 'outcome'])
            ->map(fn (EmergencyDrill $drill) => [
                'id' => $drill->id,
                'title' => $drill->title,
                'type' => $drill->drill_type,
                'scheduled_at' => $drill->scheduled_at?->toISOString(),
                'completed_at' => $drill->completed_at?->toISOString(),
                'status' => $drill->status,
                'outcome' => $drill->outcome,
            ])->values();

        $firstAid = FirstAidRecord::query()
            ->where('site_id', $site->id)
            ->orderByDesc('treatment_date')
            ->limit(20)
            ->get(['id', 'reference_number', 'treatment_date', 'treated_person_name', 'injury_illness_type', 'treatment_outcome', 'ambulance_called'])
            ->map(fn (FirstAidRecord $record) => [
                'id' => $record->id,
                'reference' => $record->reference_number,
                'treatment_date' => $record->treatment_date?->toISOString(),
                'person' => $record->treated_person_name,
                'injury' => $record->injury_illness_type,
                'outcome' => $record->treatment_outcome,
                'ambulance_called' => (bool) $record->ambulance_called,
            ])->values();

        $ppe = PpeInventory::query()
            ->where('site_id', $site->id)
            ->whereNotIn('status', ['disposed', 'lost'])
            ->with('ppeType:id,name')
            ->orderBy('expiry_date')
            ->limit(20)
            ->get(['id', 'ppe_type_id', 'brand', 'model', 'condition', 'quantity', 'status', 'expiry_date', 'next_inspection_due'])
            ->map(fn (PpeInventory $item) => [
                'id' => $item->id,
                'name' => $item->ppeType?->name ?? trim((string) $item->brand.' '.(string) $item->model),
                'condition' => $item->condition,
                'quantity' => (int) $item->quantity,
                'status' => $item->status,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'next_inspection_due' => $item->next_inspection_due?->toDateString(),
            ])->values();

        return [
            'locked' => false,
            'hazards' => [
                'items' => $hazards,
                'summary' => ['open' => $hazards->count()],
                'href' => route('sites.hazards.index', $site),
            ],
            'risk_assessments' => [
                'items' => $riskAssessments,
                'summary' => ['total' => $riskAssessments->count()],
            ],
            'inspections' => [
                'items' => $inspections,
                'summary' => [
                    'active' => $inspections->count(),
                    'overdue' => $inspections->where('overdue', true)->count(),
                ],
                'href' => route('sites.inspections.index', $site),
            ],
            'drills' => [
                'items' => $drills,
                'summary' => ['total' => $drills->count()],
            ],
            'first_aid' => [
                'items' => $firstAid,
                'summary' => ['recent' => $firstAid->count()],
            ],
            'ppe' => [
                'items' => $ppe,
                'summary' => ['items' => $ppe->count(), 'units' => $ppe->sum('quantity')],
            ],
            'emergency_plan' => [
                'summary' => [
                    'location' => $site->emergency_plan_location,
                    'medication_storage_location' => $site->medication_storage_location,
                ],
                'href' => route('sites.emergency-plan.show', $site),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function operations(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);

        $calendar = $user->canDo('calendar.view')
            ? SiteCalendarEvent::query()
                ->where('site_id', $site->id)
                ->where('start_at', '>=', now()->startOfDay())
                ->orderBy('start_at')
                ->limit(12)
                ->get(['id', 'event_type', 'title', 'start_at', 'end_at', 'status'])
                ->map(fn (SiteCalendarEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->event_type,
                    'title' => $event->title,
                    'start_at' => $event->start_at?->toISOString(),
                    'end_at' => $event->end_at?->toISOString(),
                    'status' => $event->status,
                ])->values()
            : collect();

        $checklists = $user->canDo('checklists.view')
            ? SiteChecklistRun::query()
                ->where('site_id', $site->id)
                ->with('template:id,name')
                ->orderByDesc('scheduled_date')
                ->limit(12)
                ->get(['id', 'template_id', 'scheduled_date', 'status', 'completion_percentage', 'items_failed'])
                ->map(fn (SiteChecklistRun $run) => [
                    'id' => $run->id,
                    'name' => $run->template?->name,
                    'scheduled_date' => $run->scheduled_date?->toDateString(),
                    'status' => $run->status,
                    'completion_percentage' => (float) $run->completion_percentage,
                    'items_failed' => (int) $run->items_failed,
                    'href' => route('sites.checklists.showRun', $run),
                ])->values()
            : collect();

        $assets = $this->canViewAssets($user)
            ? Asset::query()
                ->where('site_id', $site->id)
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'name', 'asset_tag', 'category', 'status', 'risk_level', 'location', 'inspection_due_at', 'maintenance_due_at'])
                ->map(fn (Asset $asset) => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                    'category' => $asset->category,
                    'status' => $asset->status,
                    'risk_level' => $asset->risk_level,
                    'location' => $asset->location,
                    'inspection_due_at' => $asset->inspection_due_at?->toDateString(),
                    'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
                    'href' => route('fleet-assets.assets.show', $asset),
                ])->values()
            : collect();

        $tenantId = (int) ($site->tenant_id ?? $user->tenant_id ?? $user->organization_id ?? 1);
        $hardwareCount = $user->canDo('securityDevices.devices.view')
            ? $this->devices->forSite($tenantId, $site->id)->count()
            : 0;

        return [
            'calendar' => [
                'locked' => ! $user->canDo('calendar.view'),
                'items' => $calendar,
                'summary' => ['upcoming' => $calendar->count()],
                'href' => $user->canDo('calendar.view') ? route('sites.calendar.index', $site) : null,
            ],
            'checklists' => [
                'locked' => ! $user->canDo('checklists.view'),
                'items' => $checklists,
                'summary' => [
                    'recent' => $checklists->count(),
                    'open' => $checklists->whereIn('status', ['scheduled', 'in_progress'])->count(),
                    'failed' => $checklists->where('items_failed', '>', 0)->count(),
                ],
                'href' => $user->canDo('checklists.view') ? route('sites.checklists.index', $site) : null,
            ],
            'meal_planner' => [
                'locked' => ! $user->canDo('sites.meals.view') || $site->type === 'head_office',
                'href' => $user->canDo('sites.meals.view') && $site->type !== 'head_office'
                    ? route('sites.meals.plan.index', $site)
                    : null,
            ],
            'assets' => [
                'locked' => ! $this->canViewAssets($user),
                'items' => $assets,
                'summary' => ['total' => $assets->count()],
                'href' => $this->canViewAssets($user) ? route('fleet-assets.assets.index', ['site_id' => $site->id]) : null,
            ],
            'fleet' => [
                'locked' => ! $user->canDo('fleet.viewAny'),
                'href' => $user->canDo('fleet.viewAny') ? route('fleet-assets.dashboard', ['site_id' => $site->id]) : null,
            ],
            'hardware' => [
                'locked' => ! $user->canDo('securityDevices.devices.view'),
                'summary' => $user->canDo('securityDevices.devices.view') ? ['total' => $hardwareCount] : null,
                'href' => $user->canDo('securityDevices.devices.view') ? route('sites.hardware.index', $site) : null,
            ],
            'plan' => [
                'summary' => $this->typePlans->summaryFor($site),
                'href' => route('sites.plan.show', $site),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function admin(User $user, Site $site): array
    {
        $this->primeViewerPermissions($user);

        $documents = SiteDocument::query()
            ->where('site_id', $site->id)
            ->with('uploadedBy:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (SiteDocument $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => $document->category,
                'folder' => $document->folder,
                'version' => $document->version,
                'effective_date' => $document->effective_date?->toDateString(),
                'expiry_date' => $document->expiry_date?->toDateString(),
                'original_name' => $document->original_name,
                'size_bytes' => $document->size_bytes,
                'uploaded_by' => $document->uploadedBy?->name,
                'created_at' => $document->created_at?->toISOString(),
                'download_href' => route('sites.documents.download', [$site, $document]),
            ])->values();

        $folders = SiteDocumentFolder::query()
            ->where('site_id', $site->id)
            ->orderBy('name')
            ->limit(100)
            ->pluck('name')
            ->values();

        $canViewVendors = $user->canDo('vendors.view');
        $canViewCredentials = $user->canDo('credentials.view');

        return [
            'documents' => [
                'items' => $documents,
                'folders' => $folders,
                'summary' => [
                    'total' => $documents->count(),
                    'expiring' => $documents->filter(fn (array $document) => $document['expiry_date']
                        && $document['expiry_date'] <= now()->addDays(60)->toDateString())->count(),
                ],
                'href' => route('sites.documents.index', $site),
            ],
            'financials' => [
                'locked' => ! $user->canDo('finance.dashboard'),
                'href' => $user->canDo('finance.dashboard')
                    ? route('finance.sites.financial-dashboard', $site)
                    : null,
            ],
            'vendors_credentials' => [
                'locked' => ! $canViewVendors && ! $canViewCredentials,
                'vendor_count' => $canViewVendors
                    ? SiteVendor::query()->where('site_id', $site->id)->where('is_active', true)->count()
                    : null,
                'credential_count' => $canViewCredentials
                    ? SiteCredential::query()->where('site_id', $site->id)->count()
                    : null,
                'href' => $canViewVendors || $canViewCredentials
                    ? route('sites.vendors.global', ['site_id' => $site->id])
                    : null,
            ],
            'services' => [
                'items' => $site->serviceContexts()
                    ->orderByDesc('is_active')
                    ->orderBy('name')
                    ->limit(100)
                    ->get(['id', 'name', 'type', 'description', 'is_active'])
                    ->map(fn ($context) => [
                        'id' => $context->id,
                        'name' => $context->name,
                        'type' => $context->type,
                        'description' => $context->description,
                        'is_active' => (bool) $context->is_active,
                    ])->values(),
            ],
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

        $quickActions = collect([
            $permissions['sites.update'] ? [
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
            'status' => $site->is_active ? 'active' : 'inactive',
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
    private function overview(Site $site): array
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
            'vendors.view', 'credentials.view',
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
                'occupied' => $site->houseRooms()->active()->whereNotNull('assigned_client_id')->count(),
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
            'houseRooms as rooms_total' => fn (Builder $query) => $query->active(),
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
