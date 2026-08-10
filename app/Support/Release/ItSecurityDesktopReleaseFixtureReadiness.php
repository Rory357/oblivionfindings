<?php

namespace App\Support\Release;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\ItCatalogItem;
use App\Models\ItChange;
use App\Models\ItKbArticle;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Throwable;
use UnexpectedValueException;

final class ItSecurityDesktopReleaseFixtureReadiness
{
    public const int SCHEMA_VERSION = 1;

    public const string EVIDENCE_CLASS = 'it_security_desktop_release_fixture_readiness_v1';

    /**
     * @var array<string, array{
     *     role: string,
     *     site: string,
     *     required_permissions: list<string>,
     *     forbidden_permissions: list<string>,
     *     mfa?: bool,
     *     explicit_denials?: list<string>
     * }>
     */
    public const array ACTORS = [
        'release-requester@acceptance.invalid' => [
            'role' => 'support_worker',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => ['it.request'],
            'forbidden_permissions' => [
                'it.view',
                'it.manage',
                'it.organisationWide',
                'it.viewSensitive',
            ],
        ],
        'release-it-manager@acceptance.invalid' => [
            'role' => 'it_manager',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => [
                'it.request',
                'it.view',
                'it.manage',
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.integrations.view',
                'securityDevices.monitoring.manage',
                'securityDevices.commands.observe',
                'securityDevices.commands.control',
                'securityDevices.commands.approve',
            ],
            'forbidden_permissions' => [
                'it.organisationWide',
                'securityDevices.devices.viewAllSites',
            ],
            'explicit_denials' => ['it.organisationWide', 'securityDevices.devices.viewAllSites'],
        ],
        'release-it-reviewer@acceptance.invalid' => [
            'role' => 'it_manager',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => [
                'it.view',
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.commands.observe',
                'securityDevices.commands.approve',
            ],
            'forbidden_permissions' => [
                'it.organisationWide',
                'securityDevices.devices.viewAllSites',
            ],
            'mfa' => true,
            'explicit_denials' => ['it.organisationWide', 'securityDevices.devices.viewAllSites'],
        ],
        'release-control-room@acceptance.invalid' => [
            'role' => 'coordinator',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => [
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.commands.observe',
            ],
            'forbidden_permissions' => ['securityDevices.devices.viewAllSites'],
        ],
        'release-auditor@acceptance.invalid' => [
            'role' => 'auditor',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.accessControl.view',
                'securityDevices.reports.view',
                'securityDevices.commands.observe',
            ],
            'forbidden_permissions' => [
                'securityDevices.devices.create',
                'securityDevices.devices.update',
                'securityDevices.devices.delete',
                'securityDevices.devices.assign',
                'securityDevices.accessControl.manage',
                'securityDevices.maintenance.manage',
                'securityDevices.integrations.manage',
                'securityDevices.monitoring.manage',
                'securityDevices.commands.operate',
                'securityDevices.commands.manage',
                'securityDevices.commands.control',
                'securityDevices.commands.approve',
                'securityDevices.commands.admin',
            ],
        ],
        'release-denied@acceptance.invalid' => [
            'role' => 'support_worker',
            'site' => 'RELEASE Site Hidden',
            'required_permissions' => [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.integrations.view',
                'securityDevices.reports.view',
                'securityDevices.commands.observe',
                'controlRoom.alerts.view',
                'it.view',
            ],
            'forbidden_permissions' => [
                'securityDevices.devices.create',
                'securityDevices.devices.update',
                'securityDevices.devices.delete',
                'securityDevices.devices.assign',
                'securityDevices.integrations.manage',
                'securityDevices.monitoring.manage',
                'securityDevices.commands.operate',
                'securityDevices.commands.manage',
                'securityDevices.commands.control',
                'securityDevices.commands.approve',
                'securityDevices.commands.admin',
                'it.manage',
                'it.organisationWide',
                'securityDevices.devices.viewAllSites',
            ],
        ],
        'release-source-denied@acceptance.invalid' => [
            'role' => 'finance',
            'site' => 'RELEASE Site Alpha',
            'required_permissions' => ['finance.dashboard'],
            'forbidden_permissions' => [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
            ],
        ],
    ];

    /** @var list<string> */
    public const array SITES = ['RELEASE Site Alpha', 'RELEASE Site Hidden'];

    /** @var array<string, string> */
    public const array CLIENTS = [
        'RELEASE Client Alpha' => 'RELEASE Site Alpha',
        'RELEASE Client Hidden' => 'RELEASE Site Hidden',
    ];

    /** @var array<string, string> */
    public const array STAFF = [
        'RELEASE Staff Alpha' => 'RELEASE Site Alpha',
        'RELEASE Staff Hidden' => 'RELEASE Site Hidden',
    ];

    /**
     * @var array<string, array{
     *     site: string,
     *     domain: string,
     *     category: string,
     *     subcategory: string,
     *     binding_type: 'site'|'client'|'asset',
     *     binding_name: string
     * }>
     */
    public const array DEVICES = [
        'RELEASE Alpha Gateway' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'router',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Alpha',
        ],
        'RELEASE Alpha Switch' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'switch',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Alpha',
        ],
        'RELEASE Alpha Door' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Alpha',
        ],
        'RELEASE Alpha Camera' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'dome_camera',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Alpha',
        ],
        'RELEASE Alpha Healthcare' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'iot_healthcare',
            'category' => 'fall_detection',
            'subcategory' => 'wearable_fall',
            'binding_type' => 'client',
            'binding_name' => 'RELEASE Client Alpha',
        ],
        'RELEASE Alpha Personal Tracker' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'tracking',
            'category' => 'personal_tracker',
            'subcategory' => 'wearable_gps',
            'binding_type' => 'client',
            'binding_name' => 'RELEASE Client Alpha',
        ],
        'RELEASE Alpha Fleet Tracker' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'tracking',
            'category' => 'vehicle_tracker',
            'subcategory' => 'hardwired_gps',
            'binding_type' => 'asset',
            'binding_name' => 'RELEASE Alpha Vehicle',
        ],
        'RELEASE Alpha Environment Sensor' => [
            'site' => 'RELEASE Site Alpha',
            'domain' => 'facilities',
            'category' => 'cold_chain',
            'subcategory' => 'fridge_sensor',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Alpha',
        ],
        'RELEASE Hidden Device' => [
            'site' => 'RELEASE Site Hidden',
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'subcategory' => 'shared_device',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE Site Hidden',
        ],
    ];

    public function __construct(private readonly CanonicalDeviceSiteResolver $deviceSites) {}

    /** @return array<string, mixed> */
    public function assess(): array
    {
        try {
            $sites = $this->sites();
            $sections = [
                'sites' => $this->siteSection($sites),
                'actors' => $this->actorSection($sites),
                'people' => $this->peopleSection($sites),
                'devices' => $this->deviceSection($sites),
                'assets' => $this->assetSection($sites),
                'it_and_control_room' => $this->itSection($sites),
            ];

            $gapCodes = collect($sections)
                ->flatMap(fn (array $section): array => $section['gap_codes'])
                ->unique()
                ->sort()
                ->values()
                ->all();

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'evidence_class' => self::EVIDENCE_CLASS,
                'state' => $gapCodes === [] ? 'ready' : 'not_ready',
                'observed_at' => now()->utc()->toIso8601String(),
                'sections' => $sections,
                'gap_codes' => $gapCodes,
                'v10_release_evidence' => false,
            ];
        } catch (Throwable) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'evidence_class' => self::EVIDENCE_CLASS,
                'state' => 'unavailable',
                'observed_at' => now()->utc()->toIso8601String(),
                'sections' => [],
                'gap_codes' => ['fixture_readiness_query_failed'],
                'v10_release_evidence' => false,
            ];
        }
    }

    /** @return Collection<string, Site> */
    private function sites(): Collection
    {
        return Site::query()
            ->whereIn('name', self::SITES)
            ->get()
            ->keyBy('name');
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function siteSection(Collection $sites): array
    {
        $ready = $sites->filter(fn (Site $site): bool => (bool) $site->is_active
            && ! (bool) $site->archived
            && $site->archived_at === null)->count();
        $gaps = [];
        if ($sites->count() !== count(self::SITES)) {
            $gaps[] = 'release_sites_missing';
        }
        if ($ready !== count(self::SITES)) {
            $gaps[] = 'release_sites_not_operational';
        }

        return $this->section(count(self::SITES), $sites->count(), $ready, $gaps);
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function actorSection(Collection $sites): array
    {
        $actors = User::query()
            ->whereIn('email', array_keys(self::ACTORS))
            ->with(['roles:id,name', 'roles.permissions:id,key', 'permissionOverrides:id,key', 'hrEmployeeProfile'])
            ->get()
            ->keyBy('email');
        $ready = 0;
        $gaps = [];

        foreach (self::ACTORS as $email => $contract) {
            $actor = $actors->get($email);
            if (! $actor instanceof User) {
                $gaps[] = 'release_actor_missing';

                continue;
            }

            $site = $sites->get($contract['site']);
            $roleNames = $actor->roles->pluck('name')->unique()->sort()->values()->all();
            $profile = $actor->hrEmployeeProfile;
            $secondarySiteIds = collect($profile?->secondary_site_ids ?? [])
                ->filter(fn (mixed $id): bool => is_numeric($id))
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $roleReady = $roleNames === [$contract['role']]
                && ! in_array('admin', $roleNames, true)
                && (string) $actor->role !== 'admin';
            $profileReady = $site instanceof Site
                && $profile !== null
                && (bool) $profile->is_active
                && ($profile->start_date === null || $profile->start_date->lte(today()))
                && ($profile->end_date === null || $profile->end_date->gte(today()))
                && (int) $profile->primary_site_id === (int) $site->id
                && $secondarySiteIds->isEmpty();
            $denialsReady = collect($contract['explicit_denials'] ?? [])->every(
                fn (string $key): bool => $actor->permissionOverrides->contains(
                    fn ($permission): bool => $permission->key === $key
                        && ! (bool) $permission->pivot->allowed,
                ),
            );
            $requiredPermissionsReady = collect($contract['required_permissions'])->every(
                fn (string $key): bool => $actor->canDo($key),
            );
            $forbiddenPermissionsReady = collect($contract['forbidden_permissions'])->every(
                fn (string $key): bool => ! $actor->canDo($key),
            );
            $mfaReady = ! ($contract['mfa'] ?? false)
                || ($actor->two_factor_secret !== null && $actor->two_factor_confirmed_at !== null);

            if ($actor->approved_at === null) {
                $gaps[] = 'release_actor_not_approved';
            }
            if (! $roleReady) {
                $gaps[] = 'release_actor_role_mismatch';
            }
            if (! $profileReady) {
                $gaps[] = 'release_actor_site_scope_mismatch';
            }
            if (! $denialsReady) {
                $gaps[] = 'release_actor_explicit_denial_missing';
            }
            if (! $requiredPermissionsReady) {
                $gaps[] = 'release_actor_required_permission_missing';
            }
            if (! $forbiddenPermissionsReady) {
                $gaps[] = 'release_actor_forbidden_permission_present';
            }
            if (! $mfaReady) {
                $gaps[] = 'release_reviewer_mfa_missing';
            }

            if ($actor->approved_at !== null
                && $roleReady
                && $profileReady
                && $denialsReady
                && $requiredPermissionsReady
                && $forbiddenPermissionsReady
                && $mfaReady) {
                $ready++;
            }
        }

        return $this->section(count(self::ACTORS), $actors->count(), $ready, $gaps);
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function peopleSection(Collection $sites): array
    {
        $clients = Client::query()
            ->where('first_name', 'RELEASE Client')
            ->whereIn('last_name', ['Alpha', 'Hidden'])
            ->get()
            ->keyBy(fn (Client $client): string => $client->full_name);
        $staff = User::query()
            ->whereIn('name', array_keys(self::STAFF))
            ->with('hrEmployeeProfile')
            ->get()
            ->keyBy('name');
        $ready = 0;
        $gaps = [];

        foreach (self::CLIENTS as $name => $siteName) {
            $client = $clients->get($name);
            $site = $sites->get($siteName);
            if (! $client instanceof Client) {
                $gaps[] = 'release_client_missing';

                continue;
            }
            if ($site instanceof Site && $client->status === 'active' && (int) $client->site_id === (int) $site->id) {
                $ready++;
            } else {
                $gaps[] = 'release_client_site_scope_mismatch';
            }
        }

        foreach (self::STAFF as $name => $siteName) {
            $user = $staff->get($name);
            $site = $sites->get($siteName);
            if (! $user instanceof User) {
                $gaps[] = 'release_staff_missing';

                continue;
            }
            $profile = $user->hrEmployeeProfile;
            if ($site instanceof Site
                && $profile !== null
                && (bool) $profile->is_active
                && (int) $profile->primary_site_id === (int) $site->id) {
                $ready++;
            } else {
                $gaps[] = 'release_staff_site_scope_mismatch';
            }
        }

        return $this->section(
            count(self::CLIENTS) + count(self::STAFF),
            $clients->count() + $staff->count(),
            $ready,
            $gaps,
        );
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function deviceSection(Collection $sites): array
    {
        $deviceRows = Device::query()
            ->whereIn('name', array_keys(self::DEVICES))
            ->with(['assignments', 'assetLinks'])
            ->get();
        $devices = $deviceRows->keyBy('name');
        $clients = Client::query()
            ->where('first_name', 'RELEASE Client')
            ->whereIn('last_name', ['Alpha', 'Hidden'])
            ->get()
            ->keyBy(fn (Client $client): string => $client->full_name);
        $assets = Asset::query()
            ->whereIn('name', ['RELEASE Alpha Vehicle', 'RELEASE Alpha Asset'])
            ->get()
            ->keyBy('name');
        $ready = 0;
        $gaps = [];
        $operational = [
            DeviceStatus::Active->value,
            DeviceStatus::Degraded->value,
            DeviceStatus::Offline->value,
        ];

        if ($deviceRows->count() !== $devices->count()) {
            $gaps[] = 'release_device_name_not_unique';
        }

        foreach (self::DEVICES as $name => $contract) {
            $device = $devices->get($name);
            $site = $sites->get($contract['site']);
            if (! $device instanceof Device) {
                $gaps[] = 'release_device_missing';

                continue;
            }

            $status = $device->status instanceof DeviceStatus ? $device->status->value : (string) $device->status;
            try {
                $siteReady = $site instanceof Site
                    && $this->deviceSites->resolve((int) $device->id) === (int) $site->id;
            } catch (UnexpectedValueException) {
                $siteReady = false;
            }

            $taxonomyReady = $device->domain === $contract['domain']
                && $device->category === $contract['category']
                && $device->subcategory === $contract['subcategory'];
            $bindingReady = $this->deviceBindingReady($device, $contract, $sites, $clients, $assets);

            if (! $taxonomyReady) {
                $gaps[] = 'release_device_taxonomy_mismatch';
            }
            if (! $bindingReady) {
                $gaps[] = 'release_device_owner_binding_mismatch';
            }

            if (in_array($status, $operational, true) && $siteReady && $taxonomyReady && $bindingReady) {
                $ready++;
            } elseif (! $siteReady || ! in_array($status, $operational, true)) {
                $gaps[] = 'release_device_canonical_scope_mismatch';
            }
        }

        return $this->section(count(self::DEVICES), $devices->count(), $ready, $gaps);
    }

    /**
     * @param  array{binding_type: 'site'|'client'|'asset', binding_name: string}  $contract
     * @param  Collection<string, Site>  $sites
     * @param  Collection<string, Client>  $clients
     * @param  Collection<string, Asset>  $assets
     */
    private function deviceBindingReady(
        Device $device,
        array $contract,
        Collection $sites,
        Collection $clients,
        Collection $assets,
    ): bool {
        if ($contract['binding_type'] === 'asset') {
            $asset = $assets->get($contract['binding_name']);
            $activeLinks = $device->assetLinks->whereNull('unlinked_at');
            $activeAssignments = $device->assignments->whereNull('released_at');

            return $asset instanceof Asset
                && $activeLinks->count() === 1
                && $activeAssignments->isEmpty()
                && (int) $activeLinks->first()->asset_id === (int) $asset->id;
        }

        $target = $contract['binding_type'] === 'site'
            ? $sites->get($contract['binding_name'])
            : $clients->get($contract['binding_name']);
        $activeAssignments = $device->assignments->whereNull('released_at');
        $activeLinks = $device->assetLinks->whereNull('unlinked_at');

        return ($target instanceof Site || $target instanceof Client)
            && $activeAssignments->count() === 1
            && $activeLinks->isEmpty()
            && $activeAssignments->first()->assignable_type === $contract['binding_type']
            && (int) $activeAssignments->first()->assignable_id === (int) $target->id;
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function assetSection(Collection $sites): array
    {
        $assetRows = Asset::query()
            ->whereIn('name', ['RELEASE Alpha Vehicle', 'RELEASE Alpha Asset'])
            ->get();
        $assets = $assetRows->groupBy('name');
        $financialRows = FinFixedAsset::query()
            ->where('asset_name', 'RELEASE Alpha Financial Record')
            ->get();
        $alpha = $sites->get('RELEASE Site Alpha');
        $ready = 0;
        $gaps = [];
        $vehicleRows = $assets->get('RELEASE Alpha Vehicle', collect());
        $assetRows = $assets->get('RELEASE Alpha Asset', collect());
        $vehicle = $vehicleRows->count() === 1 ? $vehicleRows->first() : null;
        $asset = $assetRows->count() === 1 ? $assetRows->first() : null;
        $financial = $financialRows->count() === 1 ? $financialRows->first() : null;

        if ($vehicleRows->count() > 1 || $assetRows->count() > 1) {
            $gaps[] = 'release_asset_name_not_unique';
        }

        if ($financialRows->count() > 1) {
            $gaps[] = 'release_financial_record_name_not_unique';
        }

        if ($vehicle instanceof Asset
            && $alpha instanceof Site
            && $vehicle->status === 'active'
            && strtolower((string) $vehicle->category) === 'vehicle'
            && (int) $vehicle->site_id === (int) $alpha->id
            && (int) $vehicle->home_site_id === (int) $alpha->id
            && $vehicle->client_id === null) {
            $ready++;
        } else {
            $gaps[] = $vehicleRows->isNotEmpty() ? 'release_vehicle_scope_mismatch' : 'release_vehicle_missing';
        }

        if ($asset instanceof Asset
            && $alpha instanceof Site
            && $asset->status === 'active'
            && strtolower((string) $asset->category) === 'it equipment'
            && (int) $asset->site_id === (int) $alpha->id
            && $asset->client_id === null) {
            $ready++;
        } else {
            $gaps[] = $assetRows->isNotEmpty() ? 'release_asset_scope_mismatch' : 'release_asset_missing';
        }

        if ($financial instanceof FinFixedAsset
            && $financial->status === 'active'
            && $financial->category === 'it_equipment'
            && $asset instanceof Asset
            && (int) $financial->linked_asset_id === (int) $asset->id) {
            $ready++;
        } else {
            $gaps[] = $financialRows->isNotEmpty()
                ? 'release_financial_record_link_mismatch'
                : 'release_financial_record_missing';
        }

        $present = (int) $vehicleRows->isNotEmpty()
            + (int) $assetRows->isNotEmpty()
            + (int) $financialRows->isNotEmpty();

        return $this->section(3, $present, $ready, $gaps);
    }

    /** @param Collection<string, Site> $sites
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function itSection(Collection $sites): array
    {
        $alpha = $sites->get('RELEASE Site Alpha');
        $requester = User::query()->where('email', 'release-requester@acceptance.invalid')->first();
        $manager = User::query()->where('email', 'release-it-manager@acceptance.invalid')->first();
        $checks = [];
        $checks['catalog'] = ItCatalogItem::query()
            ->where('name', 'RELEASE Access Request')
            ->where('is_published', true)
            ->where('internal_only', false)
            ->exists();
        $checks['knowledge'] = ItKbArticle::query()
            ->where('title', 'RELEASE Network Recovery')
            ->where('status', 'published')
            ->exists();

        $request = $alpha instanceof Site && $requester instanceof User
            ? ItTicket::query()
                ->where('site_id', $alpha->id)
                ->where('requester_user_id', $requester->id)
                ->where('work_type', 'service_request')
                ->first()
            : null;
        $incident = $alpha instanceof Site && $manager instanceof User
            ? ItTicket::query()
                ->where('site_id', $alpha->id)
                ->where('work_type', 'incident')
                ->where(fn ($query) => $query
                    ->where('assigned_to_user_id', $manager->id)
                    ->orWhere('owner_user_id', $manager->id))
                ->whereHas('comments', fn ($query) => $query->where('is_internal', false))
                ->whereHas('comments', fn ($query) => $query->where('is_internal', true))
                ->whereHas('attachments')
                ->whereHas('watchers')
                ->whereHas('tasks')
                ->whereHas('approvals')
                ->whereHas('links', fn ($query) => $query->where('relationship', 'affected_device'))
                ->first()
            : null;

        $checks['request'] = $request instanceof ItTicket;
        $checks['incident'] = $incident instanceof ItTicket;
        $checks['problem'] = $alpha instanceof Site && ItProblem::query()
            ->whereHas('ticket', fn ($query) => $query->where('site_id', $alpha->id))
            ->exists();
        $checks['change'] = $alpha instanceof Site && ItChange::query()
            ->whereHas('ticket', fn ($query) => $query->where('site_id', $alpha->id))
            ->exists();
        $checks['major_incident'] = $alpha instanceof Site && ItMajorIncident::query()
            ->whereHas('ticket', fn ($query) => $query->where('site_id', $alpha->id))
            ->whereHas('updates')
            ->exists();
        $checks['provisioning'] = $alpha instanceof Site && collect(['joiner', 'mover', 'leaver'])->every(
            fn (string $lifecycle): bool => ItProvisioningWorkflow::query()
                ->where('site_id_snapshot', $alpha->id)
                ->where('lifecycle_type', $lifecycle)
                ->whereHas('requests')
                ->exists(),
        );
        $checks['correlation'] = $alpha instanceof Site
            && $incident instanceof ItTicket
            && MonitoringIncidentEvidenceSnapshot::query()
                ->where('site_id', $alpha->id)
                ->where('it_ticket_id', $incident->id)
                ->whereHas('alert', fn ($query) => $query->where('site_id', $alpha->id))
                ->get()
                ->contains(fn (MonitoringIncidentEvidenceSnapshot $snapshot): bool => $snapshot->hasValidChecksum());
        $checks['control_room'] = $alpha instanceof Site && ControlRoomAlert::query()
            ->where('site_id', $alpha->id)
            ->whereIn('status', ControlRoomAlert::ACTIVE_STATUSES)
            ->exists();

        $gaps = collect($checks)
            ->reject(fn (bool $ready): bool => $ready)
            ->keys()
            ->map(fn (string $key): string => 'release_'.$key.'_fixture_missing')
            ->values()
            ->all();

        return $this->section(count($checks), collect($checks)->filter()->count(), collect($checks)->filter()->count(), $gaps);
    }

    /** @param list<string> $gapCodes
     * @return array{required: int, present: int, ready: int, gap_codes: list<string>}
     */
    private function section(int $required, int $present, int $ready, array $gapCodes): array
    {
        return [
            'required' => $required,
            'present' => min($present, $required),
            'ready' => min($ready, $required),
            'gap_codes' => collect($gapCodes)->unique()->sort()->values()->all(),
        ];
    }
}
