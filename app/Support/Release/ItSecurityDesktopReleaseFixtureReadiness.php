<?php

namespace App\Support\Release;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\ItCatalogItem;
use App\Models\ItChange;
use App\Models\ItKbArticle;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use App\Support\Monitoring\LoadSoakReleaseCheckoutVerifier;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Throwable;
use UnexpectedValueException;

final class ItSecurityDesktopReleaseFixtureReadiness
{
    public const int SCHEMA_VERSION = 1;

    public const string EVIDENCE_CLASS = 'it_security_desktop_release_fixture_readiness_v1';

    public const float TRACKING_LATITUDE = 0.0001;

    public const float TRACKING_LONGITUDE = 0.0001;

    public const string TRACKING_EVENT_PROVIDER = 'release_fixture';

    public const string TRACKING_EVENT_SOURCE_APP = 'desktop_release_acceptance';

    public const string TRACKING_EVENT_SOURCE_ID = 'release-v10-alpha-personal-tracker-synthetic-position-v1';

    public const string TRACKING_EVENT_TYPE = 'location_report';

    public const array TRACKING_EVENT_TAGS = [
        'fixture' => true,
        'privacy_class' => 'non_private',
        'synthetic' => true,
    ];

    public const array TRACKING_EVENT_PAYLOAD = [
        'battery_pct' => 82,
        'lat' => self::TRACKING_LATITUDE,
        'lng' => self::TRACKING_LONGITUDE,
        'privacy_class' => 'non_private',
        'synthetic' => true,
    ];

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
        'release-v10-requester@acceptance.invalid' => [
            'role' => 'support_worker',
            'site' => 'RELEASE V10 Site Alpha',
            'required_permissions' => ['it.request'],
            'forbidden_permissions' => [
                'it.view',
                'it.manage',
                'it.organisationWide',
                'it.viewSensitive',
            ],
        ],
        'release-v10-it-manager@acceptance.invalid' => [
            'role' => 'it_manager',
            'site' => 'RELEASE V10 Site Alpha',
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
        'release-v10-it-reviewer@acceptance.invalid' => [
            'role' => 'it_manager',
            'site' => 'RELEASE V10 Site Alpha',
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
        'release-v10-control-room@acceptance.invalid' => [
            'role' => 'coordinator',
            'site' => 'RELEASE V10 Site Alpha',
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
        'release-v10-auditor@acceptance.invalid' => [
            'role' => 'auditor',
            'site' => 'RELEASE V10 Site Alpha',
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
        'release-v10-denied@acceptance.invalid' => [
            'role' => 'support_worker',
            'site' => 'RELEASE V10 Site Hidden',
            'required_permissions' => [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'securityDevices.events.view',
                'securityDevices.integrations.view',
                'securityDevices.reports.view',
                'securityDevices.commands.observe',
                // These parent grants deliberately let the direct-route checks
                // reach Site/object authorization. The actor is assigned only
                // to RELEASE V10 Site Hidden, so Alpha records must still be
                // concealed by their canonical access services.
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
                'fleet.viewAny',
                'assets.telemetry.view',
                'assets.telemetry.export',
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
        'release-v10-source-denied@acceptance.invalid' => [
            'role' => 'finance',
            'site' => 'RELEASE V10 Site Alpha',
            'required_permissions' => ['finance.dashboard'],
            'forbidden_permissions' => [
                'securityDevices.viewAny',
                'securityDevices.devices.view',
                'controlRoom.viewAny',
                'controlRoom.alerts.view',
            ],
        ],
    ];

    /** @var array<string, string> */
    public const array ACTOR_EMPLOYEE_NUMBERS = [
        'release-v10-requester@acceptance.invalid' => 'REL-V10-ACTOR-01',
        'release-v10-it-manager@acceptance.invalid' => 'REL-V10-ACTOR-02',
        'release-v10-it-reviewer@acceptance.invalid' => 'REL-V10-ACTOR-03',
        'release-v10-control-room@acceptance.invalid' => 'REL-V10-ACTOR-04',
        'release-v10-auditor@acceptance.invalid' => 'REL-V10-ACTOR-05',
        'release-v10-denied@acceptance.invalid' => 'REL-V10-ACTOR-06',
        'release-v10-source-denied@acceptance.invalid' => 'REL-V10-ACTOR-07',
    ];

    /** @var list<string> */
    public const array SITES = ['RELEASE V10 Site Alpha', 'RELEASE V10 Site Hidden'];

    /** @var array<string, string> */
    public const array CLIENTS = [
        'RELEASE V10 Client Alpha' => 'RELEASE V10 Site Alpha',
        'RELEASE V10 Client Hidden' => 'RELEASE V10 Site Hidden',
    ];

    /** @var array<string, string> */
    public const array STAFF = [
        'RELEASE V10 Staff Alpha' => 'RELEASE V10 Site Alpha',
        'RELEASE V10 Staff Hidden' => 'RELEASE V10 Site Hidden',
    ];

    /** @var array<string, string> */
    public const array STAFF_EMPLOYEE_NUMBERS = [
        'RELEASE V10 Staff Alpha' => 'REL-V10-STAFF-ALPHA',
        'RELEASE V10 Staff Hidden' => 'REL-V10-STAFF-HIDDEN',
    ];

    /**
     * @var array<string, array{
     *     site: string,
     *     domain: string,
     *     category: string,
     *     subcategory: string,
     *     binding_type: 'site'|'client'|'asset',
     *     binding_name: string,
     *     release_fixture_command?: bool
     * }>
     */
    public const array DEVICES = [
        'RELEASE V10 Alpha Gateway' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'router',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
        ],
        'RELEASE V10 Alpha Switch' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'switch',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
        ],
        'RELEASE V10 Alpha Door' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
            'release_fixture_command' => true,
        ],
        'RELEASE V10 Alpha Door Secondary' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
            'release_fixture_command' => true,
        ],
        'RELEASE V10 Alpha Camera' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'security',
            'category' => 'cctv',
            'subcategory' => 'dome_camera',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
        ],
        'RELEASE V10 Alpha Healthcare' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'iot_healthcare',
            'category' => 'fall_detection',
            'subcategory' => 'wearable_fall',
            'binding_type' => 'client',
            'binding_name' => 'RELEASE V10 Client Alpha',
        ],
        'RELEASE V10 Alpha Personal Tracker' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'tracking',
            'category' => 'personal_tracker',
            'subcategory' => 'wearable_gps',
            'binding_type' => 'client',
            'binding_name' => 'RELEASE V10 Client Alpha',
        ],
        'RELEASE V10 Alpha Fleet Tracker' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'tracking',
            'category' => 'vehicle_tracker',
            'subcategory' => 'hardwired_gps',
            'binding_type' => 'asset',
            'binding_name' => 'RELEASE V10 Alpha Vehicle',
        ],
        'RELEASE V10 Alpha Environment Sensor' => [
            'site' => 'RELEASE V10 Site Alpha',
            'domain' => 'facilities',
            'category' => 'cold_chain',
            'subcategory' => 'fridge_sensor',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Alpha',
        ],
        'RELEASE V10 Hidden Device' => [
            'site' => 'RELEASE V10 Site Hidden',
            'domain' => 'it_infrastructure',
            'category' => 'endpoint',
            'subcategory' => 'shared_device',
            'binding_type' => 'site',
            'binding_name' => 'RELEASE V10 Site Hidden',
        ],
    ];

    public function __construct(
        private readonly CanonicalDeviceSiteResolver $deviceSites,
        private readonly CommandObservationFreshnessService $commandFreshness,
        private readonly ?Closure $environment = null,
        private readonly ?Closure $verifyCheckout = null,
    ) {}

    /** @return array<string, mixed> */
    public function assess(bool $requireRuntimePack = false): array
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
            if ($requireRuntimePack) {
                $sections['runtime'] = $this->runtimeSection();
            }

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

    /** @return array{required: int, present: int, ready: int, gap_codes: list<string>} */
    private function runtimeSection(): array
    {
        $revision = config('it.desktop_release_fixtures.release_revision');
        $revisionValid = is_string($revision) && preg_match('/\A[0-9a-f]{40}\z/', $revision) === 1;
        $runtimeApproved = $this->environment() === 'staging'
            && config('it.desktop_release_fixtures.enabled') === true
            && config('it.desktop_release_fixtures.environment_class') === 'approved_non_production';
        $checkoutVerified = $revisionValid && $this->checkoutMatchesReleaseRevision($revision);
        $packs = ItSecurityDesktopReleaseFixturePack::query()
            ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
            ->get(['release_revision', 'state']);
        $pack = $packs->count() === 1 ? $packs->sole() : null;
        $packReady = $pack instanceof ItSecurityDesktopReleaseFixturePack
            && $pack->state === ItSecurityDesktopReleaseFixturePack::STATE_READY;
        $revisionMatches = $packReady && $revisionValid
            && hash_equals($revision, (string) $pack->release_revision);

        $gaps = [];
        if (! $runtimeApproved) {
            $gaps[] = 'release_fixture_runtime_not_approved';
        }
        if (! $revisionValid) {
            $gaps[] = 'release_fixture_runtime_revision_invalid';
        }
        if ($revisionValid && ! $checkoutVerified) {
            $gaps[] = 'release_fixture_runtime_checkout_revision_mismatch';
        }
        if ($packs->count() !== 1) {
            $gaps[] = 'release_fixture_runtime_pack_missing';
        } elseif (! $packReady) {
            $gaps[] = 'release_fixture_runtime_pack_not_ready';
        } elseif (! $revisionMatches) {
            $gaps[] = 'release_fixture_runtime_pack_revision_mismatch';
        }

        return $this->section(5, 5 - count($gaps), $gaps === [] ? 5 : 0, $gaps);
    }

    private function environment(): string
    {
        return $this->environment instanceof Closure
            ? (string) ($this->environment)()
            : (string) app()->environment();
    }

    private function checkoutMatchesReleaseRevision(string $revision): bool
    {
        try {
            return $this->verifyCheckout instanceof Closure
                ? ($this->verifyCheckout)(base_path(), $revision)
                : (new LoadSoakReleaseCheckoutVerifier)->verify(base_path(), $revision);
        } catch (Throwable) {
            return false;
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
        $siteRows = Site::query()->whereIn('name', self::SITES)->count();
        $ready = $sites->filter(fn (Site $site): bool => (bool) $site->is_active
            && ! (bool) $site->archived
            && $site->archived_at === null)->count();
        $gaps = [];
        if ($sites->count() !== count(self::SITES)) {
            $gaps[] = 'release_sites_missing';
        }
        if ($siteRows !== $sites->count()) {
            $gaps[] = 'release_site_name_not_unique';
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
                && $profile->employee_number === self::ACTOR_EMPLOYEE_NUMBERS[$email]
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
        $clientRows = Client::query()
            ->where('first_name', 'RELEASE V10 Client')
            ->whereIn('last_name', ['Alpha', 'Hidden'])
            ->get();
        $clients = $clientRows->keyBy(fn (Client $client): string => $client->full_name);
        $staffRows = User::query()
            ->whereIn('name', array_keys(self::STAFF))
            ->with('hrEmployeeProfile')
            ->get();
        $staff = $staffRows->keyBy('name');
        $ready = 0;
        $gaps = [];

        if ($clientRows->count() !== $clients->count()) {
            $gaps[] = 'release_client_name_not_unique';
        }
        if ($staffRows->count() !== $staff->count()) {
            $gaps[] = 'release_staff_name_not_unique';
        }

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
                && $profile->employee_number === self::STAFF_EMPLOYEE_NUMBERS[$name]
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
            ->with(['assignments.consent.consentType', 'assetLinks'])
            ->get();
        $devices = $deviceRows->keyBy('name');
        $clients = Client::query()
            ->where('first_name', 'RELEASE V10 Client')
            ->whereIn('last_name', ['Alpha', 'Hidden'])
            ->get()
            ->keyBy(fn (Client $client): string => $client->full_name);
        $assets = Asset::query()
            ->whereIn('name', ['RELEASE V10 Alpha Vehicle', 'RELEASE V10 Alpha Asset'])
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
        $expectedFixtureCommandNames = collect(self::DEVICES)
            ->filter(fn (array $contract): bool => ($contract['release_fixture_command'] ?? false) === true)
            ->keys()
            ->sort()
            ->values()
            ->all();
        $actualFixtureCommandNames = Device::query()
            ->where('provider', 'release_fixture')
            ->where('name', 'like', 'RELEASE V10 %')
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
        if ($actualFixtureCommandNames !== $expectedFixtureCommandNames) {
            $gaps[] = 'release_fixture_command_device_set_mismatch';
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
            $trackingBaselineReady = $name !== 'RELEASE V10 Alpha Personal Tracker'
                || $this->personalTrackingBaselineReady($device, $clients->get('RELEASE V10 Client Alpha'));
            $trackingHistoryReady = $name !== 'RELEASE V10 Alpha Personal Tracker'
                || $this->personalTrackingHistoryBaselineReady($device, $clients->get('RELEASE V10 Client Alpha'));
            $fixtureCommandReady = ! ($contract['release_fixture_command'] ?? false)
                || ($device->provider === 'release_fixture'
                    && ($device->config ?? []) === [
                        'management' => [
                            'capabilities' => ['access.door.unlock_timed'],
                            'release_fixture' => ['no_network' => true],
                        ],
                    ]);
            $fixtureCommandObservationReady = ! ($contract['release_fixture_command'] ?? false)
                || $this->commandFreshness->inspect($device)->isFresh();

            if (! $taxonomyReady) {
                $gaps[] = 'release_device_taxonomy_mismatch';
            }
            if (! $bindingReady) {
                $gaps[] = 'release_device_owner_binding_mismatch';
            }
            if (! $trackingBaselineReady) {
                $gaps[] = 'release_personal_tracking_consent_baseline_missing';
            }
            if (! $trackingHistoryReady) {
                $gaps[] = 'release_personal_tracking_history_baseline_missing';
            }
            if (! $fixtureCommandReady) {
                $gaps[] = 'release_fixture_command_device_contract_mismatch';
            }
            if (! $fixtureCommandObservationReady) {
                $gaps[] = 'release_fixture_command_observation_stale';
            }

            if (in_array($status, $operational, true)
                && $siteReady
                && $taxonomyReady
                && $bindingReady
                && $trackingBaselineReady
                && $trackingHistoryReady
                && $fixtureCommandReady
                && $fixtureCommandObservationReady) {
                $ready++;
            } elseif (! $siteReady || ! in_array($status, $operational, true)) {
                $gaps[] = 'release_device_canonical_scope_mismatch';
            }
        }

        return $this->section(count(self::DEVICES), $devices->count(), $ready, $gaps);
    }

    private function personalTrackingBaselineReady(Device $device, ?Client $client): bool
    {
        $assignments = $device->assignments->whereNull('released_at');
        if ($assignments->count() !== 1) {
            return false;
        }
        $assignment = $assignments->first();
        $consent = $assignment?->consent;
        $type = $consent?->consentType;
        $consentAssignments = $consent instanceof ClientConsent
            ? DeviceAssignment::query()
                ->where('consent_id', $consent->id)
                ->whereNull('released_at')
                ->get()
            : collect();

        return $client instanceof Client
            && $assignment !== null
            && (int) $assignment->assignable_id === (int) $client->id
            && $assignment->assignable_type === 'client'
            && $consent instanceof ClientConsent
            && $type instanceof ConsentType
            && $consent->status === 'given'
            && $consent->withdrawn_at === null
            && ($consent->expires_at === null || $consent->expires_at->isFuture())
            && $type->name === 'RELEASE V10 Client Location Tracking'
            && $type->purpose === 'Client personal safety tracking'
            && $assignment->tracking_purpose === 'Client personal safety tracking'
            && $assignment->authority_basis === 'assignment_linked_client_consent'
            && $consentAssignments->count() === 1
            && (int) $consentAssignments->sole()->id === (int) $assignment->id
            && $assignment->isCollectionActive();
    }

    private function personalTrackingHistoryBaselineReady(Device $device, ?Client $client): bool
    {
        if (! $client instanceof Client) {
            return false;
        }

        $eventRows = IntegrationEvent::query()
            ->where('source_event_id', self::TRACKING_EVENT_SOURCE_ID)
            ->get();
        if ($eventRows->count() !== 1) {
            return false;
        }

        $event = $eventRows->sole();
        $assignment = $device->assignments->whereNull('released_at')->first();
        $retentionDays = max(1, (int) ($assignment?->retention_days ?? 90));

        return (float) $device->latitude === self::TRACKING_LATITUDE
            && (float) $device->longitude === self::TRACKING_LONGITUDE
            && $device->last_seen_at !== null
            && $event->occurred_at !== null
            && $device->last_seen_at->equalTo($event->occurred_at)
            && $event->occurred_at->gte(now()->subDays($retentionDays))
            && $event->occurred_at->lte(now())
            && $event->received_at?->equalTo($event->occurred_at) === true
            && (int) $event->site_id === (int) $client->site_id
            && (int) $event->canonical_device_id === (int) $device->id
            && $event->room_id === null
            && $event->hardware_id === null
            && $event->provider === self::TRACKING_EVENT_PROVIDER
            && $event->source_app === self::TRACKING_EVENT_SOURCE_APP
            && $event->event_type === self::TRACKING_EVENT_TYPE
            && $event->severity === IntegrationEvent::SEVERITY_INFO
            && Arr::sortRecursive((array) $event->tags) === Arr::sortRecursive(self::TRACKING_EVENT_TAGS)
            && Arr::sortRecursive((array) $event->normalized_payload) === Arr::sortRecursive(self::TRACKING_EVENT_PAYLOAD)
            && $event->raw_payload === null;
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
            ->whereIn('name', ['RELEASE V10 Alpha Vehicle', 'RELEASE V10 Alpha Asset'])
            ->get();
        $assets = $assetRows->groupBy('name');
        $financialRows = FinFixedAsset::query()
            ->where('asset_name', 'RELEASE V10 Alpha Financial Record')
            ->get();
        $alpha = $sites->get('RELEASE V10 Site Alpha');
        $ready = 0;
        $gaps = [];
        $vehicleRows = $assets->get('RELEASE V10 Alpha Vehicle', collect());
        $assetRows = $assets->get('RELEASE V10 Alpha Asset', collect());
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
        $alpha = $sites->get('RELEASE V10 Site Alpha');
        $requester = User::query()->where('email', 'release-v10-requester@acceptance.invalid')->first();
        $manager = User::query()->where('email', 'release-v10-it-manager@acceptance.invalid')->first();
        $switchRows = Device::query()->where('name', 'RELEASE V10 Alpha Switch')->get();
        $switch = $switchRows->count() === 1 ? $switchRows->first() : null;
        $checks = [];
        $checks['catalog'] = ItCatalogItem::query()
            ->where('name', 'RELEASE V10 Access Request')
            ->where('is_published', true)
            ->where('internal_only', false)
            ->exists();
        $checks['knowledge'] = ItKbArticle::query()
            ->where('title', 'RELEASE V10 Network Recovery')
            ->where('status', 'published')
            ->exists();

        $request = $alpha instanceof Site && $requester instanceof User
            ? ItTicket::query()
                ->where('site_id', $alpha->id)
                ->where('requester_user_id', $requester->id)
                ->where('work_type', 'service_request')
                ->first()
            : null;
        $incident = $alpha instanceof Site && $manager instanceof User && $switch instanceof Device
            ? ItTicket::query()
                ->where('site_id', $alpha->id)
                ->where('work_type', 'incident')
                ->where('source', 'system')
                ->where('is_organisation_wide', false)
                ->where(fn ($query) => $query
                    ->where('assigned_to_user_id', $manager->id)
                    ->orWhere('owner_user_id', $manager->id))
                ->whereHas('comments', fn ($query) => $query->where('is_internal', false))
                ->whereHas('comments', fn ($query) => $query->where('is_internal', true))
                ->whereHas('attachments')
                ->whereHas('watchers')
                ->whereHas('tasks')
                ->whereHas('approvals')
                ->whereHas('links', fn ($query) => $query
                    ->where('relationship', 'affected_device')
                    ->where('linkable_type', $switch->getMorphClass())
                    ->where('linkable_id', $switch->id)
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION))
                ->whereHas('links', fn ($query) => $query
                    ->where('relationship', 'source_alert')
                    ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                    ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION))
                ->first()
            : null;

        $snapshots = $alpha instanceof Site && $incident instanceof ItTicket && $switch instanceof Device
            ? MonitoringIncidentEvidenceSnapshot::query()
                ->with(['alert', 'deviceEvent'])
                ->where('site_id', $alpha->id)
                ->where('it_ticket_id', $incident->id)
                ->where('device_id', $switch->id)
                ->get()
                ->filter(fn (MonitoringIncidentEvidenceSnapshot $snapshot): bool => $snapshot->hasValidChecksum())
            : collect();
        $snapshot = $snapshots->count() === 1 ? $snapshots->first() : null;
        $canonicalAlertLinked = $snapshot instanceof MonitoringIncidentEvidenceSnapshot
            && $incident instanceof ItTicket
            && $incident->links()
                ->where('relationship', 'source_alert')
                ->where('linkable_type', $snapshot->alert?->getMorphClass())
                ->where('linkable_id', $snapshot->control_room_alert_id)
                ->where('context->system_principal', ItTicketLinkService::MONITORING_PRINCIPAL)
                ->where('context->operation', ItTicketLinkService::MONITORING_OPERATION)
                ->count() === 1;
        $canonicalMonitoringAlert = $snapshot instanceof MonitoringIncidentEvidenceSnapshot
            && $snapshot->alert instanceof ControlRoomAlert
            && $snapshot->alert->source === 'oblivion_monitoring'
            && (int) $snapshot->alert->site_id === (int) $alpha?->id
            && in_array($snapshot->alert->status, ControlRoomAlert::ACTIVE_STATUSES, true);
        $canonicalMonitoringEvent = $snapshot instanceof MonitoringIncidentEvidenceSnapshot
            && $snapshot->deviceEvent !== null
            && (int) $snapshot->deviceEvent->device_id === (int) $switch?->id
            && $snapshot->deviceEvent->source === 'oblivion_monitoring';

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
        $checks['correlation'] = $canonicalAlertLinked
            && $canonicalMonitoringAlert
            && $canonicalMonitoringEvent;
        $checks['control_room'] = $canonicalMonitoringAlert;

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
