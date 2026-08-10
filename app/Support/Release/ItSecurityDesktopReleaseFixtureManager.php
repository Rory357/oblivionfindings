<?php

namespace App\Support\Release;

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Services\MonitoringIncidentEvidenceService;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Enums\LinkType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItChange;
use App\Models\ItKbArticle;
use App\Models\ItMajorIncident;
use App\Models\ItMajorIncidentUpdate;
use App\Models\ItProblem;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItTicketComment;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ItSecurityDesktopReleaseFixtureManager
{
    public const int SCHEMA_VERSION = 1;

    public const string EVIDENCE_CLASS = 'it_security_desktop_release_fixture_management_v1';

    private const string ATTACHMENT_PATH = 'it-security-release-fixtures/release-network-evidence.txt';

    private const string ATTACHMENT_CONTENT = "Non-sensitive desktop release acceptance evidence.\n";

    /** @var list<array{type: string, id: int}> */
    private array $records = [];

    /** @var array<string, class-string<Model>> */
    private const array RECORD_MODELS = [
        'asset' => Asset::class,
        'client' => Client::class,
        'control_room_alert' => ControlRoomAlert::class,
        'device' => Device::class,
        'device_asset_link' => DeviceAssetLink::class,
        'device_assignment' => DeviceAssignment::class,
        'device_event' => DeviceEvent::class,
        'fin_fixed_asset' => FinFixedAsset::class,
        'hr_employee_profile' => HrEmployeeProfile::class,
        'it_attachment' => ItAttachment::class,
        'it_catalog_item' => ItCatalogItem::class,
        'it_change' => ItChange::class,
        'it_kb_article' => ItKbArticle::class,
        'it_major_incident' => ItMajorIncident::class,
        'it_major_incident_update' => ItMajorIncidentUpdate::class,
        'it_problem' => ItProblem::class,
        'it_provisioning_request' => ItProvisioningRequest::class,
        'it_provisioning_workflow' => ItProvisioningWorkflow::class,
        'it_ticket' => ItTicket::class,
        'it_ticket_approval' => ItTicketApproval::class,
        'it_ticket_comment' => ItTicketComment::class,
        'it_ticket_link' => ItTicketLink::class,
        'it_work_task' => ItWorkTask::class,
        'site' => Site::class,
        'user' => User::class,
    ];

    public function __construct(
        private readonly ItSecurityDesktopReleaseFixtureReadiness $readiness,
        private readonly ItTicketLinkService $ticketLinks,
        private readonly MonitoringIncidentEvidenceService $incidentEvidence,
    ) {}

    /** @return array<string, mixed> */
    public function plan(string $action, string $revision): array
    {
        return match ($action) {
            'prepare' => $this->planPrepare($revision),
            'cleanup' => $this->planCleanup($revision),
            default => $this->report('failed', $action, $revision, 'dry_run', ['release_fixture_mutation_action_not_allowed']),
        };
    }

    /** @return array<string, mixed> */
    public function execute(string $action, string $revision): array
    {
        return match ($action) {
            'prepare' => $this->prepare($revision),
            'cleanup' => $this->cleanup($revision),
            default => $this->report('failed', $action, $revision, 'execute', ['release_fixture_mutation_action_not_allowed']),
        };
    }

    /** @return array<string, mixed> */
    private function planPrepare(string $revision): array
    {
        $pack = $this->pack();
        if ($pack) {
            $gaps = $this->packGaps($pack, requireReadiness: true);

            return $this->report(
                $gaps === [] ? 'ready' : 'failed',
                'prepare',
                $revision,
                'dry_run',
                $gaps,
                operation: 'reuse',
                recordCount: count((array) data_get($pack->manifest, 'records', [])),
            );
        }

        $gaps = [...$this->prerequisiteGaps(), ...$this->reservedIdentityGaps()];

        return $this->report(
            $gaps === [] ? 'ready' : 'failed',
            'prepare',
            $revision,
            'dry_run',
            $gaps,
            operation: 'create',
        );
    }

    /** @return array<string, mixed> */
    private function planCleanup(string $revision): array
    {
        $pack = $this->pack();
        if (! $pack) {
            return $this->report('ready', 'cleanup', $revision, 'dry_run', [], operation: 'no_op');
        }

        $gaps = $this->packGaps($pack, requireReadiness: false);

        return $this->report(
            $gaps === [] ? 'ready' : 'failed',
            'cleanup',
            $revision,
            'dry_run',
            $gaps,
            operation: 'delete_owned',
            recordCount: count((array) data_get($pack->manifest, 'records', [])),
        );
    }

    /** @return array<string, mixed> */
    private function prepare(string $revision): array
    {
        $planned = $this->planPrepare($revision);
        if (($planned['state'] ?? null) !== 'ready') {
            return [...$planned, 'mode' => 'execute'];
        }

        $existing = $this->pack();
        if ($existing) {
            DB::transaction(function () use ($existing, $revision): void {
                $locked = ItSecurityDesktopReleaseFixturePack::query()
                    ->whereKey($existing->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $gaps = $this->packGaps($locked, requireReadiness: true);
                if ($gaps !== []) {
                    throw new DomainException('The owned release fixture pack is no longer complete.');
                }
                $locked->update([
                    'release_revision' => $revision,
                    'last_verified_at' => now(),
                ]);
            });

            return $this->report(
                'ready',
                'prepare',
                $revision,
                'execute',
                [],
                operation: 'reused',
                mutationApplied: true,
                recordCount: count((array) data_get($existing->manifest, 'records', [])),
            );
        }

        $this->records = [];
        $fileCreated = false;

        try {
            $pack = DB::transaction(function () use ($revision, &$fileCreated): ItSecurityDesktopReleaseFixturePack {
                if ($this->pack() || $this->prerequisiteGaps() !== [] || $this->reservedIdentityGaps() !== []) {
                    throw new DomainException('Release fixture prerequisites changed before execution.');
                }

                $context = $this->createCoreRecords();
                $this->createItAndControlRecords($context);
                $this->writeAttachmentFile();
                $fileCreated = true;

                $manifest = $this->manifest();
                $readiness = $this->readiness->assess();
                if (($readiness['state'] ?? null) !== 'ready') {
                    throw new DomainException('The prepared release fixture pack did not satisfy readiness.');
                }

                return ItSecurityDesktopReleaseFixturePack::query()->create([
                    'pack_key' => ItSecurityDesktopReleaseFixturePack::PACK_KEY,
                    'release_revision' => $revision,
                    'state' => ItSecurityDesktopReleaseFixturePack::STATE_READY,
                    'manifest' => $manifest,
                    'manifest_sha256' => $this->manifestHash($manifest),
                    'prepared_at' => now(),
                    'last_verified_at' => now(),
                ]);
            }, 1);
        } catch (Throwable $exception) {
            if ($fileCreated) {
                Storage::disk(ItAttachment::DISK)->delete(self::ATTACHMENT_PATH);
            }

            throw $exception;
        }

        return $this->report(
            'ready',
            'prepare',
            $revision,
            'execute',
            [],
            operation: 'created',
            mutationApplied: true,
            recordCount: count((array) data_get($pack->manifest, 'records', [])),
        );
    }

    /** @return array<string, mixed> */
    private function cleanup(string $revision): array
    {
        $planned = $this->planCleanup($revision);
        if (($planned['state'] ?? null) !== 'ready' || ($planned['operation'] ?? null) === 'no_op') {
            return [...$planned, 'mode' => 'execute'];
        }

        $restoreFile = false;
        try {
            DB::transaction(function () use (&$restoreFile): void {
                $pack = ItSecurityDesktopReleaseFixturePack::query()
                    ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($this->packGaps($pack, requireReadiness: false) !== []) {
                    throw new DomainException('The owned release fixture pack failed its cleanup integrity check.');
                }

                $restoreFile = Storage::disk(ItAttachment::DISK)->exists(self::ATTACHMENT_PATH);
                if ($restoreFile && ! Storage::disk(ItAttachment::DISK)->delete(self::ATTACHMENT_PATH)) {
                    throw new DomainException('The owned release fixture attachment could not be removed.');
                }

                $records = array_reverse((array) data_get($pack->manifest, 'records', []));
                foreach ($records as $record) {
                    $this->deleteOwnedRecord($record);
                }
                $pack->delete();
            }, 1);
        } catch (Throwable $exception) {
            if ($restoreFile && ! Storage::disk(ItAttachment::DISK)->exists(self::ATTACHMENT_PATH)) {
                Storage::disk(ItAttachment::DISK)->put(self::ATTACHMENT_PATH, self::ATTACHMENT_CONTENT);
            }

            throw $exception;
        }

        return $this->report(
            'ready',
            'cleanup',
            $revision,
            'execute',
            [],
            operation: 'deleted_owned',
            mutationApplied: true,
            recordCount: (int) ($planned['record_count'] ?? 0),
        );
    }

    /**
     * @return array{
     *   sites: array<string, Site>,
     *   actors: array<string, User>,
     *   staff: array<string, User>,
     *   profiles: array<string, HrEmployeeProfile>,
     *   clients: array<string, Client>,
     *   assets: array<string, Asset>,
     *   devices: array<string, Device>
     * }
     */
    private function createCoreRecords(): array
    {
        $sites = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::SITES as $index => $name) {
            $sites[$name] = $this->own('site', Site::query()->create([
                'name' => $name,
                'type' => 'facility',
                'tenant_id' => 1,
                'address_line_1' => ($index + 1).' Release Lane',
                'city' => 'Acceptance City',
                'country' => 'New Zealand',
                'is_active' => true,
                'archived' => false,
                'archived_at' => null,
            ]));
        }

        $password = (string) config('it.desktop_release_fixtures.actor_password');
        $totpSecret = (string) config('it.desktop_release_fixtures.reviewer_totp_secret');
        $roles = Role::query()
            ->whereIn('name', collect(ItSecurityDesktopReleaseFixtureReadiness::ACTORS)->pluck('role')->unique())
            ->get()
            ->keyBy('name');
        $permissions = Permission::query()
            ->whereIn('key', $this->permissionKeys())
            ->get()
            ->keyBy('key');

        $actors = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::ACTORS as $email => $contract) {
            $name = Str::headline(str_replace(['release-', '@acceptance.invalid'], ['', ''], $email));
            $actor = $this->own('user', User::query()->create([
                'name' => 'RELEASE '.$name,
                'email' => $email,
                'password' => $password,
                'role' => $contract['role'],
                'approved_at' => now(),
            ]));
            $actor->forceFill(['email_verified_at' => now()]);
            if ($contract['mfa'] ?? false) {
                $actor->forceFill([
                    'two_factor_secret' => encrypt($totpSecret),
                    'two_factor_recovery_codes' => encrypt(json_encode([
                        Str::random(10),
                        Str::random(10),
                    ], JSON_THROW_ON_ERROR)),
                    'two_factor_confirmed_at' => now(),
                ]);
            }
            $actor->saveQuietly();
            $actor->roles()->attach($roles->get($contract['role'])->id);

            $permissionSync = [];
            foreach ($contract['required_permissions'] as $key) {
                $permissionSync[$permissions->get($key)->id] = ['allowed' => true];
            }
            foreach ($contract['forbidden_permissions'] as $key) {
                $permissionSync[$permissions->get($key)->id] = ['allowed' => false];
            }
            $actor->permissionOverrides()->sync($permissionSync);

            $site = $sites[$contract['site']];
            $this->own('hr_employee_profile', HrEmployeeProfile::query()->create([
                'user_id' => $actor->id,
                'employee_number' => 'REL-ACTOR-'.str_pad((string) (count($actors) + 1), 2, '0', STR_PAD_LEFT),
                'work_email' => $email,
                'position_title' => Str::headline($contract['role']),
                'position_role' => $contract['role'],
                'employment_type' => 'full_time',
                'start_date' => today()->subYear(),
                'is_active' => true,
                'primary_site_id' => $site->id,
                'secondary_site_ids' => [],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]));
            $actors[$email] = $actor;
        }

        $manager = $actors['release-it-manager@acceptance.invalid'];
        $staff = [];
        $profiles = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::STAFF as $name => $siteName) {
            $suffix = str_ends_with($name, 'Alpha') ? 'alpha' : 'hidden';
            $user = $this->own('user', User::query()->create([
                'name' => $name,
                'email' => "release-staff-{$suffix}@acceptance.invalid",
                'password' => $password,
                'role' => 'support_worker',
                'approved_at' => now(),
                'approved_by' => $manager->id,
            ]));
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            $user->roles()->attach($roles->get('support_worker')->id);
            $profiles[$name] = $this->own('hr_employee_profile', HrEmployeeProfile::query()->create([
                'user_id' => $user->id,
                'employee_number' => 'REL-STAFF-'.strtoupper($suffix),
                'work_email' => $user->email,
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'start_date' => today()->subYear(),
                'is_active' => true,
                'primary_site_id' => $sites[$siteName]->id,
                'secondary_site_ids' => [],
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ]));
            $staff[$name] = $user;
        }

        $clients = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::CLIENTS as $name => $siteName) {
            [, , $lastName] = explode(' ', $name, 3);
            $clients[$name] = $this->own('client', Client::query()->create([
                'site_id' => $sites[$siteName]->id,
                'first_name' => 'RELEASE Client',
                'last_name' => $lastName,
                'status' => 'active',
                'service_start_date' => today()->subYear(),
            ]));
        }

        $assets = [
            'RELEASE Alpha Vehicle' => $this->own('asset', Asset::query()->create([
                'site_id' => $sites['RELEASE Site Alpha']->id,
                'home_site_id' => $sites['RELEASE Site Alpha']->id,
                'client_id' => null,
                'created_by_user_id' => $manager->id,
                'updated_by_user_id' => $manager->id,
                'asset_tag' => 'REL-VEHICLE-001',
                'qr_token' => hash('sha256', 'it-security-release-vehicle'),
                'name' => 'RELEASE Alpha Vehicle',
                'category' => 'Vehicle',
                'status' => 'active',
                'risk_level' => 'low',
            ])),
            'RELEASE Alpha Asset' => $this->own('asset', Asset::query()->create([
                'site_id' => $sites['RELEASE Site Alpha']->id,
                'client_id' => null,
                'created_by_user_id' => $manager->id,
                'updated_by_user_id' => $manager->id,
                'asset_tag' => 'REL-ASSET-001',
                'qr_token' => hash('sha256', 'it-security-release-asset'),
                'name' => 'RELEASE Alpha Asset',
                'category' => 'IT Equipment',
                'status' => 'active',
                'risk_level' => 'low',
            ])),
        ];

        $this->own('fin_fixed_asset', FinFixedAsset::query()->create([
            'organization_id' => 1,
            'asset_name' => 'RELEASE Alpha Financial Record',
            'asset_tag' => 'REL-FIN-001',
            'category' => 'it_equipment',
            'purchase_date' => today()->subYear(),
            'purchase_cost' => 1000,
            'residual_value' => 100,
            'useful_life_months' => 36,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $assets['RELEASE Alpha Asset']->id,
            'created_by' => $manager->id,
        ]));

        $devices = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::DEVICES as $name => $contract) {
            $device = $this->own('device', Device::query()->create([
                'name' => $name,
                'domain' => $contract['domain'],
                'category' => $contract['category'],
                'subcategory' => $contract['subcategory'],
                'manufacturer' => 'Acceptance Hardware',
                'model' => 'Release Fixture',
                'serial_number' => 'REL-'.strtoupper(substr(hash('sha256', $name), 0, 12)),
                'status' => DeviceStatus::Active,
                'health_status' => HealthStatus::Healthy,
                'last_seen_at' => now(),
                'provider' => 'manual',
                'created_by_user_id' => $manager->id,
            ]));

            if ($contract['binding_type'] === 'asset') {
                $this->own('device_asset_link', DeviceAssetLink::query()->create([
                    'device_id' => $device->id,
                    'asset_id' => $assets[$contract['binding_name']]->id,
                    'link_type' => LinkType::InstalledIn,
                    'linked_at' => now(),
                    'linked_by_user_id' => $manager->id,
                    'notes' => 'Owned desktop release acceptance link.',
                ]));
            } else {
                $target = $contract['binding_type'] === 'site'
                    ? $sites[$contract['binding_name']]
                    : $clients[$contract['binding_name']];
                $this->own('device_assignment', DeviceAssignment::query()->create([
                    'device_id' => $device->id,
                    'assignable_type' => $contract['binding_type'],
                    'assignable_id' => $target->id,
                    'assignment_type' => 'permanent',
                    'assigned_at' => now(),
                    'assigned_by_user_id' => $manager->id,
                    'notes' => 'Owned desktop release acceptance assignment.',
                ]));
            }
            $devices[$name] = $device;
        }

        return compact('sites', 'actors', 'staff', 'profiles', 'clients', 'assets', 'devices');
    }

    /** @param array<string, mixed> $context */
    private function createItAndControlRecords(array $context): void
    {
        /** @var array<string, Site> $sites */
        $sites = $context['sites'];
        /** @var array<string, User> $actors */
        $actors = $context['actors'];
        /** @var array<string, HrEmployeeProfile> $profiles */
        $profiles = $context['profiles'];
        /** @var array<string, Device> $devices */
        $devices = $context['devices'];
        $alpha = $sites['RELEASE Site Alpha'];
        $requester = $actors['release-requester@acceptance.invalid'];
        $manager = $actors['release-it-manager@acceptance.invalid'];
        $reviewer = $actors['release-it-reviewer@acceptance.invalid'];
        $switch = $devices['RELEASE Alpha Switch'];

        $this->own('it_catalog_item', ItCatalogItem::query()->create([
            'name' => 'RELEASE Access Request',
            'slug' => 'release-access-request',
            'description' => 'Request governed access for desktop release acceptance.',
            'outcome_type' => 'service_request',
            'category' => 'account',
            'default_priority' => 'normal',
            'requires_approval' => true,
            'is_published' => true,
            'internal_only' => false,
            'form_schema_version' => 1,
            'form_schema' => ['fields' => []],
            'search_terms' => ['release', 'access'],
            'sort_order' => 0,
            'created_by' => $manager->id,
            'updated_by' => $manager->id,
        ]));
        $this->own('it_kb_article', ItKbArticle::query()->create([
            'title' => 'RELEASE Network Recovery',
            'slug' => 'release-network-recovery',
            'category' => 'network',
            'body' => 'Use the governed monitoring and incident workflow to confirm recovery.',
            'status' => 'published',
            'audience' => 'all_staff',
            'site_scope' => [],
            'author_user_id' => $manager->id,
            'owner_user_id' => $manager->id,
            'reviewed_by_user_id' => $reviewer->id,
            'review_due_at' => today()->addMonths(6),
            'review_started_at' => now(),
            'published_at' => now(),
        ]));

        $this->own('it_ticket', ItTicket::createWithReference([
            'title' => 'RELEASE Requester Access Request',
            'description' => 'Requester-visible desktop release acceptance request.',
            'requester_user_id' => $requester->id,
            'site_id' => $alpha->id,
            'is_organisation_wide' => false,
            'category' => 'account',
            'source' => 'portal',
            'work_type' => 'service_request',
            'workflow_state' => 'submitted',
            'priority' => 'normal',
            'impact' => 'individual',
            'urgency' => 'normal',
            'status' => 'open',
            'sla_state' => 'ok',
            'next_action' => 'IT review',
        ]));

        $incident = $this->own('it_ticket', ItTicket::createWithReference([
            'title' => 'RELEASE Alpha Switch Connectivity Incident',
            'description' => 'Canonical monitoring incident for desktop release acceptance.',
            'requester_user_id' => null,
            'assigned_to_user_id' => $manager->id,
            'owner_user_id' => $manager->id,
            'site_id' => $alpha->id,
            'is_organisation_wide' => false,
            'category' => 'network',
            'source' => 'system',
            'work_type' => 'incident',
            'workflow_state' => 'investigating',
            'priority' => 'high',
            'impact' => 'site',
            'urgency' => 'high',
            'status' => 'in_progress',
            'requires_approval' => true,
            'sla_state' => 'ok',
            'next_action' => 'Validate monitored recovery',
        ]));
        $this->own('it_ticket_comment', ItTicketComment::query()->create([
            'ticket_id' => $incident->id,
            'author_user_id' => $requester->id,
            'body' => 'The shared connection is unavailable.',
            'is_internal' => false,
        ]));
        $this->own('it_ticket_comment', ItTicketComment::query()->create([
            'ticket_id' => $incident->id,
            'author_user_id' => $manager->id,
            'body' => 'Internal diagnostic evidence retained for the IT operator.',
            'is_internal' => true,
        ]));
        $this->own('it_attachment', ItAttachment::query()->create([
            'attachable_type' => $incident->getMorphClass(),
            'attachable_id' => $incident->id,
            'path' => self::ATTACHMENT_PATH,
            'original_name' => 'release-network-evidence.txt',
            'mime' => 'text/plain',
            'size' => strlen(self::ATTACHMENT_CONTENT),
            'uploaded_by' => $manager->id,
        ]));
        $incident->watchers()->attach($reviewer->id);
        $watcherId = DB::table('it_ticket_watchers')
            ->where('ticket_id', $incident->id)
            ->where('user_id', $reviewer->id)
            ->value('id');
        if (! is_numeric($watcherId)) {
            throw new DomainException('The release incident watcher was not persisted.');
        }
        $this->records[] = ['type' => 'it_ticket_watcher', 'id' => (int) $watcherId];
        $this->own('it_work_task', ItWorkTask::query()->create([
            'ticket_id' => $incident->id,
            'assigned_to_user_id' => $manager->id,
            'title' => 'Validate canonical monitoring recovery',
            'status' => 'in_progress',
            'is_required' => true,
            'evidence_required' => true,
            'sort_order' => 1,
        ]));
        $this->own('it_ticket_approval', ItTicketApproval::query()->create([
            'it_ticket_id' => $incident->id,
            'requested_by' => $manager->id,
            'approver_id' => $reviewer->id,
            'status' => 'approved',
            'reason' => 'Independent release acceptance approval.',
            'decided_at' => now(),
        ]));

        $event = DeviceEvent::withoutEvents(fn (): DeviceEvent => $this->own(
            'device_event',
            DeviceEvent::query()->create([
                'device_id' => $switch->id,
                'event_type' => 'offline',
                'severity' => 'high',
                'payload' => ['message' => 'Release acceptance connectivity interruption.'],
                'source' => 'oblivion_monitoring',
                'occurred_at' => now()->subMinute(),
                'processed_at' => now(),
            ]),
        ));
        $alert = $this->own('control_room_alert', ControlRoomAlert::query()->create([
            'source' => 'oblivion_monitoring',
            'alert_type' => 'Device Offline',
            'severity' => 'high',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'site_id' => $alpha->id,
            'triggered_at' => $event->occurred_at,
            'assigned_to_user_id' => $actors['release-control-room@acceptance.invalid']->id,
            'assigned_at' => now(),
            'assigned_by_user_id' => $manager->id,
            'created_by_user_id' => $manager->id,
            'context' => [
                'normalized_data' => [
                    'canonical_device_id' => $switch->id,
                    'device_event_id' => $event->id,
                ],
            ],
        ]));
        $this->ticketLinks->linkMonitoringEvidence($incident, $switch, $alert, [
            'release_fixture' => true,
        ]);
        foreach ($incident->links()->whereIn('relationship', ['affected_device', 'source_alert'])->get() as $link) {
            $this->own('it_ticket_link', $link);
        }
        $snapshot = $this->incidentEvidence->captureIfMissing(
            $incident,
            $switch,
            $alert,
            $event,
            hash('sha256', 'release-alpha-switch-monitor'),
        );
        $this->records[] = ['type' => 'monitoring_incident_evidence_snapshot', 'id' => (int) $snapshot->id];

        $problemTicket = $this->workTicket('RELEASE Recurring Network Problem', 'problem', $alpha, $requester, $manager);
        $this->own('it_problem', ItProblem::query()->create([
            'ticket_id' => $problemTicket->id,
            'impact_summary' => 'Intermittent release acceptance connectivity.',
            'workaround' => 'Use the secondary monitored path.',
            'created_by_user_id' => $manager->id,
            'updated_by_user_id' => $manager->id,
        ]));
        $changeTicket = $this->workTicket('RELEASE Network Change', 'change', $alpha, $requester, $manager);
        $this->own('it_change', ItChange::query()->create([
            'ticket_id' => $changeTicket->id,
            'change_type' => 'normal',
            'risk_level' => 'medium',
            'is_restricted' => false,
            'impact_summary' => 'Bounded release acceptance change.',
            'implementation_plan' => 'Apply the reversible fixture change.',
            'validation_plan' => 'Verify the canonical monitoring path.',
            'backout_plan' => 'Restore the prior fixture state.',
            'maintenance_starts_at' => now()->addDay(),
            'maintenance_ends_at' => now()->addDay()->addHour(),
            'created_by_user_id' => $manager->id,
            'updated_by_user_id' => $manager->id,
        ]));
        $majorTicket = $this->workTicket('RELEASE Major Connectivity Incident', 'major_incident', $alpha, $requester, $manager);
        $major = $this->own('it_major_incident', ItMajorIncident::query()->create([
            'ticket_id' => $majorTicket->id,
            'severity' => 'sev2',
            'impact_summary' => 'Release acceptance connectivity interruption.',
            'commander_user_id' => $manager->id,
            'communications_lead_user_id' => $manager->id,
            'target_update_minutes' => 30,
            'declared_at' => now()->subMinutes(10),
            'next_update_due_at' => now()->addMinutes(20),
            'created_by_user_id' => $manager->id,
            'updated_by_user_id' => $manager->id,
        ]));
        $this->own('it_major_incident_update', ItMajorIncidentUpdate::query()->create([
            'major_incident_id' => $major->id,
            'update_kind' => 'stakeholder_update',
            'audience' => 'staff',
            'summary' => 'Release acceptance investigation is in progress.',
            'service_status' => 'degraded',
            'published_at' => now(),
            'author_user_id' => $manager->id,
        ]));

        foreach (['joiner', 'mover', 'leaver'] as $offset => $lifecycle) {
            $workflow = $this->own('it_provisioning_workflow', ItProvisioningWorkflow::query()->create([
                'employee_profile_id' => $profiles['RELEASE Staff Alpha']->id,
                'lifecycle_type' => $lifecycle,
                'source_type' => 'release_fixture',
                'source_id' => $offset + 1,
                'source_event_key' => "release-desktop-{$lifecycle}",
                'status' => 'pending',
                'effective_at' => now()->addDays($offset),
                'role_snapshot' => 'support_worker',
                'site_id_snapshot' => $alpha->id,
                'employment_type_snapshot' => 'full_time',
                'changes' => $lifecycle === 'mover' ? ['site' => ['state' => 'review_required']] : null,
                'created_by_user_id' => $manager->id,
            ]));
            $this->own('it_provisioning_request', ItProvisioningRequest::query()->create([
                'employee_profile_id' => $profiles['RELEASE Staff Alpha']->id,
                'provisioning_workflow_id' => $workflow->id,
                'type' => 'access',
                'task_key' => "release-{$lifecycle}-access",
                'action' => $lifecycle === 'leaver' ? 'revoke' : 'provision',
                'category' => 'access',
                'item' => Str::headline($lifecycle).' access review',
                'assigned_to_user_id' => $manager->id,
                'status' => 'pending',
                'priority' => 'normal',
                'stage' => 1,
                'approval_required' => true,
                'approval_status' => 'pending',
                'evidence_required' => true,
                'created_by' => $manager->id,
            ]));
        }
    }

    private function workTicket(string $title, string $workType, Site $site, User $requester, User $manager): ItTicket
    {
        return $this->own('it_ticket', ItTicket::createWithReference([
            'title' => $title,
            'description' => 'Owned desktop release acceptance work record.',
            'requester_user_id' => $requester->id,
            'assigned_to_user_id' => $manager->id,
            'owner_user_id' => $manager->id,
            'site_id' => $site->id,
            'is_organisation_wide' => false,
            'category' => 'network',
            'source' => 'agent',
            'work_type' => $workType,
            'workflow_state' => $workType === 'major_incident' ? 'declared' : 'investigating',
            'priority' => $workType === 'major_incident' ? 'urgent' : 'high',
            'impact' => 'site',
            'urgency' => 'high',
            'status' => 'in_progress',
            'sla_state' => 'ok',
            'next_action' => 'Continue governed acceptance work',
        ]));
    }

    /** @return list<string> */
    private function prerequisiteGaps(): array
    {
        $gaps = [];
        $password = config('it.desktop_release_fixtures.actor_password');
        $totpSecret = config('it.desktop_release_fixtures.reviewer_totp_secret');
        if (! is_string($password) || strlen($password) < 12) {
            $gaps[] = 'release_fixture_actor_password_missing';
        }
        if (! is_string($totpSecret)
            || preg_match('/\A[A-Z2-7]{16,128}\z/', $totpSecret) !== 1) {
            $gaps[] = 'release_fixture_reviewer_totp_secret_missing';
        }

        $roleNames = collect(ItSecurityDesktopReleaseFixtureReadiness::ACTORS)
            ->pluck('role')->unique()->sort()->values();
        if (Role::query()->whereIn('name', $roleNames)->distinct()->count('name') !== $roleNames->count()) {
            $gaps[] = 'release_fixture_required_roles_missing';
        }
        $permissionKeys = $this->permissionKeys();
        if (Permission::query()->whereIn('key', $permissionKeys)->distinct()->count('key') !== count($permissionKeys)) {
            $gaps[] = 'release_fixture_required_permissions_missing';
        }

        return $this->sortedGaps($gaps);
    }

    /** @return list<string> */
    private function reservedIdentityGaps(): array
    {
        $collisions = User::query()->whereIn('email', [
            ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS),
            'release-staff-alpha@acceptance.invalid',
            'release-staff-hidden@acceptance.invalid',
        ])->exists()
            || User::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::STAFF))->exists()
            || Site::withTrashed()->whereIn('name', ItSecurityDesktopReleaseFixtureReadiness::SITES)->exists()
            || Client::withTrashed()->where('first_name', 'RELEASE Client')->whereIn('last_name', ['Alpha', 'Hidden'])->exists()
            || Asset::query()->whereIn('name', ['RELEASE Alpha Vehicle', 'RELEASE Alpha Asset'])->exists()
            || FinFixedAsset::withTrashed()->where('asset_name', 'RELEASE Alpha Financial Record')->exists()
            || Device::withTrashed()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->exists()
            || ItCatalogItem::withTrashed()->where('slug', 'release-access-request')->exists()
            || ItKbArticle::query()->where('slug', 'release-network-recovery')->exists()
            || ItProvisioningWorkflow::query()->whereIn('source_event_key', [
                'release-desktop-joiner',
                'release-desktop-mover',
                'release-desktop-leaver',
            ])->exists()
            || Storage::disk(ItAttachment::DISK)->exists(self::ATTACHMENT_PATH);

        return $collisions ? ['release_fixture_reserved_identity_present'] : [];
    }

    /** @return list<string> */
    private function permissionKeys(): array
    {
        return collect(ItSecurityDesktopReleaseFixtureReadiness::ACTORS)
            ->flatMap(fn (array $contract): array => [
                ...$contract['required_permissions'],
                ...$contract['forbidden_permissions'],
            ])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function packGaps(ItSecurityDesktopReleaseFixturePack $pack, bool $requireReadiness): array
    {
        $gaps = [];
        $manifest = $pack->manifest;
        if ($pack->pack_key !== ItSecurityDesktopReleaseFixturePack::PACK_KEY
            || $pack->state !== ItSecurityDesktopReleaseFixturePack::STATE_READY
            || ! is_array($manifest)
            || ! hash_equals((string) $pack->manifest_sha256, $this->manifestHash($manifest))
            || ! $this->manifestShapeValid($manifest)) {
            $gaps[] = 'release_fixture_pack_integrity_failed';

            return $gaps;
        }

        foreach ($manifest['records'] as $record) {
            if (! $this->ownedRecordExists($record)) {
                $gaps[] = 'release_fixture_owned_record_missing';
                break;
            }
        }
        $file = $manifest['files'][0];
        $disk = Storage::disk(ItAttachment::DISK);
        try {
            $contents = $disk->exists($file['path']) ? $disk->get($file['path']) : null;
        } catch (Throwable) {
            $contents = null;
        }
        if (! is_string($contents) || ! hash_equals($file['sha256'], hash('sha256', $contents))) {
            $gaps[] = 'release_fixture_owned_file_mismatch';
        }
        if ($requireReadiness && ($this->readiness->assess()['state'] ?? null) !== 'ready') {
            $gaps[] = 'release_fixture_readiness_failed';
        }

        return $this->sortedGaps($gaps);
    }

    /** @param array<string, mixed> $manifest */
    private function manifestShapeValid(array $manifest): bool
    {
        $manifestKeys = array_keys($manifest);
        sort($manifestKeys, SORT_STRING);
        if ($manifestKeys !== ['files', 'records', 'schema_version']
            || $manifest['schema_version'] !== 1
            || ! is_array($manifest['records'])
            || ! array_is_list($manifest['records'])
            || ! is_array($manifest['files'])
            || ! array_is_list($manifest['files'])
            || count($manifest['files']) !== 1
            || ! is_array($manifest['files'][0])
            || array_diff_key($manifest['files'][0], ['path' => true, 'sha256' => true]) !== []
            || array_diff_key(['path' => true, 'sha256' => true], $manifest['files'][0]) !== []
            || ($manifest['files'][0]['path'] ?? null) !== self::ATTACHMENT_PATH
            || ($manifest['files'][0]['sha256'] ?? null) !== hash('sha256', self::ATTACHMENT_CONTENT)) {
            return false;
        }

        $seen = [];
        foreach ($manifest['records'] as $record) {
            if (! is_array($record)
                || array_diff_key($record, ['type' => true, 'id' => true]) !== []
                || array_diff_key(['type' => true, 'id' => true], $record) !== []
                || ! is_string($record['type'])
                || (! isset(self::RECORD_MODELS[$record['type']])
                    && ! in_array($record['type'], ['it_ticket_watcher', 'monitoring_incident_evidence_snapshot'], true))
                || ! is_int($record['id'])
                || $record['id'] < 1) {
                return false;
            }
            $key = $record['type'].':'.$record['id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return $manifest['records'] !== [];
    }

    /** @param array{type: string, id: int} $record */
    private function ownedRecordExists(array $record): bool
    {
        return match ($record['type']) {
            'it_ticket_watcher' => DB::table('it_ticket_watchers')->where('id', $record['id'])->exists(),
            'monitoring_incident_evidence_snapshot' => DB::table('monitoring_incident_evidence_snapshots')->where('id', $record['id'])->exists(),
            default => $this->modelQuery(self::RECORD_MODELS[$record['type']])->whereKey($record['id'])->exists(),
        };
    }

    /** @param array{type: string, id: int} $record */
    private function deleteOwnedRecord(array $record): void
    {
        if ($record['type'] === 'it_ticket_watcher') {
            DB::table('it_ticket_watchers')->where('id', $record['id'])->delete();

            return;
        }
        if ($record['type'] === 'monitoring_incident_evidence_snapshot') {
            DB::table('monitoring_incident_evidence_snapshots')->where('id', $record['id'])->delete();

            return;
        }

        $class = self::RECORD_MODELS[$record['type']];
        $model = $this->modelQuery($class)->whereKey($record['id'])->first();
        if (! $model) {
            throw new DomainException('An owned release fixture record disappeared during cleanup.');
        }
        if ($model instanceof User) {
            $model->roles()->detach();
            $model->permissionOverrides()->detach();
        }
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $model->forceDelete();
        } else {
            $model->delete();
        }
    }

    /** @param class-string<Model> $class */
    private function modelQuery(string $class)
    {
        $query = $class::query();

        return in_array(SoftDeletes::class, class_uses_recursive($class), true)
            ? $query->withTrashed()
            : $query;
    }

    private function writeAttachmentFile(): void
    {
        $disk = Storage::disk(ItAttachment::DISK);
        if ($disk->exists(self::ATTACHMENT_PATH)
            || ! $disk->put(self::ATTACHMENT_PATH, self::ATTACHMENT_CONTENT)
            || ! hash_equals(hash('sha256', self::ATTACHMENT_CONTENT), hash('sha256', (string) $disk->get(self::ATTACHMENT_PATH)))) {
            throw new DomainException('The owned release fixture attachment could not be created exactly.');
        }
    }

    /** @return array{schema_version: int, records: list<array{type: string, id: int}>, files: list<array{path: string, sha256: string}>} */
    private function manifest(): array
    {
        return [
            'schema_version' => 1,
            'records' => $this->records,
            'files' => [[
                'path' => self::ATTACHMENT_PATH,
                'sha256' => hash('sha256', self::ATTACHMENT_CONTENT),
            ]],
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function manifestHash(array $manifest): string
    {
        return hash('sha256', json_encode(
            $this->canonicalValue($manifest),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /** @template T of Model
     * @param  T  $model
     * @return T
     */
    private function own(string $type, Model $model): Model
    {
        $this->records[] = ['type' => $type, 'id' => (int) $model->getKey()];

        return $model;
    }

    private function pack(): ?ItSecurityDesktopReleaseFixturePack
    {
        return ItSecurityDesktopReleaseFixturePack::query()
            ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
            ->first();
    }

    /** @param list<string> $gaps
     * @return list<string>
     */
    private function sortedGaps(array $gaps): array
    {
        $gaps = array_values(array_unique($gaps));
        sort($gaps);

        return $gaps;
    }

    /** @param list<string> $gaps
     * @return array<string, mixed>
     */
    private function report(
        string $state,
        string $action,
        string $revision,
        string $mode,
        array $gaps,
        ?string $operation = null,
        bool $mutationApplied = false,
        int $recordCount = 0,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'evidence_class' => self::EVIDENCE_CLASS,
            'state' => $state,
            'action' => in_array($action, ['prepare', 'cleanup'], true) ? $action : null,
            'release_revision' => preg_match('/\A[0-9a-f]{40}\z/', $revision) === 1 ? $revision : null,
            'mode' => $mode,
            'operation' => $operation,
            'record_count' => $recordCount,
            'gap_codes' => $this->sortedGaps($gaps),
            'fixture_mutation_applied' => $mutationApplied,
            'v10_release_evidence' => false,
        ];
    }
}
