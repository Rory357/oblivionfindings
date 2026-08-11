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
use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\ItAttachment;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItChange;
use App\Models\ItEmailDelivery;
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
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\ItWorkTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ItSecurityDesktopReleaseFixtureManager
{
    public const int SCHEMA_VERSION = 1;

    public const string EVIDENCE_CLASS = 'it_security_desktop_release_fixture_management_v1';

    private const string ATTACHMENT_PATH = 'it-security-release-fixtures/v10/release-network-evidence.txt';

    private const string ATTACHMENT_CONTENT = "Non-sensitive desktop release acceptance evidence.\n";

    private const string TRACKING_SCOPE_GAP = 'release_fixture_tracking_consent_assignment_scope_mismatch';

    private const string TRACKING_HISTORY_GAP = 'release_fixture_tracking_history_baseline_mismatch';

    /** @var list<array{type: string, id: int}> */
    private array $records = [];

    /** @var array<string, class-string<Model>> */
    private const array RECORD_MODELS = [
        'asset' => Asset::class,
        'client' => Client::class,
        'client_consent' => ClientConsent::class,
        'consent_type' => ConsentType::class,
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
        'integration_event' => IntegrationEvent::class,
        'site' => Site::class,
        'user' => User::class,
    ];

    public function __construct(
        private readonly ItSecurityDesktopReleaseFixtureReadiness $readiness,
        private readonly ItTicketLinkService $ticketLinks,
        private readonly MonitoringIncidentEvidenceService $incidentEvidence,
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
    ) {}

    /** @return array<string, mixed> */
    public function plan(string $action, string $revision): array
    {
        return match ($action) {
            'prepare' => $this->planPrepare($revision),
            'cleanup' => $this->planCleanup($revision),
            'reset' => $this->planReset($revision),
            'withdraw-tracking-consent' => $this->planWithdrawTrackingConsent($revision),
            default => $this->report('failed', $action, $revision, 'dry_run', ['release_fixture_mutation_action_not_allowed']),
        };
    }

    /** @return array<string, mixed> */
    public function execute(string $action, string $revision): array
    {
        return match ($action) {
            'prepare' => $this->prepare($revision),
            'cleanup' => $this->cleanup($revision),
            'reset' => $this->reset($revision),
            'withdraw-tracking-consent' => $this->withdrawTrackingConsent($revision),
            default => $this->report('failed', $action, $revision, 'execute', ['release_fixture_mutation_action_not_allowed']),
        };
    }

    /** @return array<string, mixed> */
    private function planPrepare(string $revision): array
    {
        $pack = $this->pack();
        if ($pack) {
            $gaps = $this->packGaps($pack, requireReadiness: true);
            $retained = $this->retainedD16EvidenceGaps($pack) !== [];
            if ($retained && ! hash_equals($revision, (string) $pack->release_revision)) {
                $gaps[] = 'release_fixture_retained_d16_evidence_requires_pack_archive';
            }

            return $this->report(
                $gaps === [] ? 'ready' : 'failed',
                'prepare',
                $revision,
                'dry_run',
                $gaps,
                operation: $retained ? 'retain' : 'reuse',
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

        $pending = $pack->state === ItSecurityDesktopReleaseFixturePack::STATE_CLEANUP_FILES_PENDING;
        $gaps = $pending
            ? $this->pendingCleanupGaps($pack)
            : [
                ...$this->packGaps($pack, requireReadiness: false),
                ...$this->retainedD16EvidenceGaps($pack),
            ];

        return $this->report(
            $gaps === [] ? 'ready' : 'failed',
            'cleanup',
            $revision,
            'dry_run',
            $gaps,
            operation: $pending ? 'resume_file_cleanup' : 'delete_owned',
            recordCount: count((array) data_get($pack->manifest, 'records', [])),
        );
    }

    /** @return array<string, mixed> */
    private function planReset(string $revision): array
    {
        $pack = $this->pack();
        if (! $pack) {
            return $this->report('failed', 'reset', $revision, 'dry_run', ['release_fixture_pack_missing']);
        }

        $gaps = $this->packGaps($pack, requireReadiness: false);
        if ($gaps === []) {
            $gaps = [
                ...$this->trackingFixtureMutationGaps($pack),
                ...$this->commandFixtureMutationGaps($pack),
            ];
        }

        return $this->report(
            $gaps === [] ? 'ready' : 'failed',
            'reset',
            $revision,
            'dry_run',
            $gaps,
            operation: 'restore_owned_mutable_baseline',
        );
    }

    /** @return array<string, mixed> */
    private function planWithdrawTrackingConsent(string $revision): array
    {
        $pack = $this->pack();
        if (! $pack) {
            return $this->report('failed', 'withdraw-tracking-consent', $revision, 'dry_run', ['release_fixture_pack_missing']);
        }

        $gaps = $this->packGaps($pack, requireReadiness: false);
        if ($gaps === []) {
            $gaps = $this->trackingFixtureMutationGaps($pack);
        }
        if ($gaps === [] && ($this->readiness->assess()['state'] ?? null) !== 'ready') {
            $gaps[] = 'release_fixture_readiness_failed';
        }

        return $this->report(
            $gaps === [] ? 'ready' : 'failed',
            'withdraw-tracking-consent',
            $revision,
            'dry_run',
            $gaps,
            operation: 'withdraw_owned_tracking_consent',
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
            $retained = false;
            DB::transaction(function () use ($existing, $revision, &$retained): void {
                $locked = ItSecurityDesktopReleaseFixturePack::query()
                    ->whereKey($existing->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $gaps = $this->packGaps($locked, requireReadiness: true);
                if ($gaps !== []) {
                    throw new DomainException('The owned release fixture pack is no longer complete.');
                }
                if ($this->retainedD16EvidenceGaps($locked) !== []) {
                    $retained = true;
                    if (! hash_equals($revision, (string) $locked->release_revision)) {
                        throw new DomainException('release_fixture_retained_d16_evidence_requires_pack_archive');
                    }

                    return;
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
                operation: $retained ? 'retained' : 'reused',
                mutationApplied: ! $retained,
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
                    $gapCodes = collect((array) ($readiness['gap_codes'] ?? []))
                        ->filter(fn (mixed $gap): bool => is_string($gap) && $gap !== '')
                        ->sort()
                        ->values()
                        ->implode(',');

                    throw new DomainException('The prepared release fixture pack did not satisfy readiness: '.$gapCodes);
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

        DB::transaction(function (): void {
            $pack = ItSecurityDesktopReleaseFixturePack::query()
                ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            if ($pack->state === ItSecurityDesktopReleaseFixturePack::STATE_CLEANUP_FILES_PENDING) {
                if ($this->pendingCleanupGaps($pack) !== []) {
                    throw new DomainException('The release fixture cleanup journal failed its integrity check.');
                }

                return;
            }
            if ($this->packGaps($pack, requireReadiness: false) !== []) {
                throw new DomainException('The owned release fixture pack failed its cleanup integrity check.');
            }
            if ($this->retainedD16EvidenceGaps($pack) !== []) {
                throw new DomainException('release_fixture_retained_d16_evidence_requires_pack_archive');
            }

            $this->deleteD01JourneyRecords($pack);
            $records = array_reverse((array) data_get($pack->manifest, 'records', []));
            foreach ($records as $record) {
                $this->deleteOwnedRecord($record);
            }
            $pack->update([
                'state' => ItSecurityDesktopReleaseFixturePack::STATE_CLEANUP_FILES_PENDING,
                'last_verified_at' => now(),
            ]);
        }, 1);

        $this->finishPendingCleanupFiles();

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

    /** @return array<string, mixed> */
    private function reset(string $revision): array
    {
        $planned = $this->planReset($revision);
        if (($planned['state'] ?? null) !== 'ready') {
            return [...$planned, 'mode' => 'execute'];
        }

        $gaps = DB::transaction(function (): array {
            $pack = $this->lockedPack();
            $gaps = $this->packGaps($pack, requireReadiness: false);
            if ($gaps !== []) {
                return $gaps;
            }
            $gaps = $this->trackingFixtureMutationGaps($pack, lock: true);
            if ($gaps !== []) {
                return $gaps;
            }
            $gaps = $this->commandFixtureMutationGaps($pack, lock: true);
            if ($gaps !== []) {
                return $gaps;
            }
            $this->restoreTrackingBaseline($pack);
            $this->refreshCommandFixtureObservationBaseline($pack);
            if (($this->readiness->assess()['state'] ?? null) !== 'ready') {
                throw new DomainException('The owned mutable fixture baseline did not recover.');
            }

            return [];
        }, 1);
        if ($gaps !== []) {
            return $this->report('failed', 'reset', $revision, 'execute', $gaps, operation: 'restore_owned_mutable_baseline');
        }

        return $this->report('ready', 'reset', $revision, 'execute', [], operation: 'restored_owned_mutable_baseline', mutationApplied: true);
    }

    /** @return array<string, mixed> */
    private function withdrawTrackingConsent(string $revision): array
    {
        $planned = $this->planWithdrawTrackingConsent($revision);
        if (($planned['state'] ?? null) !== 'ready') {
            return [...$planned, 'mode' => 'execute'];
        }

        $gaps = DB::transaction(function (): array {
            $pack = $this->lockedPack();
            $gaps = $this->packGaps($pack, requireReadiness: false);
            if ($gaps !== []) {
                return $gaps;
            }
            $gaps = $this->trackingFixtureMutationGaps($pack, lock: true);
            if ($gaps !== []) {
                return $gaps;
            }
            if (($this->readiness->assess()['state'] ?? null) !== 'ready') {
                return ['release_fixture_readiness_failed'];
            }
            [$consent, , $actor] = $this->trackingFixtureRecords($pack, lock: true);
            $consent->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
                'withdrawn_by_user_id' => $actor->id,
                'withdrawal_reason' => 'Approved non-production desktop release fixture transition.',
                'updated_by' => $actor->id,
            ]);
            $this->trackingPrivacy->stopForConsent($consent->fresh(), $actor->id);

            return [];
        }, 1);
        if ($gaps !== []) {
            return $this->report('failed', 'withdraw-tracking-consent', $revision, 'execute', $gaps, operation: 'withdraw_owned_tracking_consent');
        }

        return $this->report('ready', 'withdraw-tracking-consent', $revision, 'execute', [], operation: 'withdrew_owned_tracking_consent', mutationApplied: true);
    }

    /**
     * @return array{
     *   sites: array<string, Site>,
     *   actors: array<string, User>,
     *   staff: array<string, User>,
     *   profiles: array<string, HrEmployeeProfile>,
     *   clients: array<string, Client>,
     *   consents: array<string, ClientConsent>,
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
                'name' => 'RELEASE V10 '.$name,
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
                'employee_number' => ItSecurityDesktopReleaseFixtureReadiness::ACTOR_EMPLOYEE_NUMBERS[$email],
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

        $manager = $actors['release-v10-it-manager@acceptance.invalid'];
        $staff = [];
        $profiles = [];
        foreach (ItSecurityDesktopReleaseFixtureReadiness::STAFF as $name => $siteName) {
            $suffix = str_ends_with($name, 'Alpha') ? 'alpha' : 'hidden';
            $user = $this->own('user', User::query()->create([
                'name' => $name,
                'email' => "release-v10-staff-{$suffix}@acceptance.invalid",
                'password' => $password,
                'role' => 'support_worker',
                'approved_at' => now(),
                'approved_by' => $manager->id,
            ]));
            $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            $user->roles()->attach($roles->get('support_worker')->id);
            $profiles[$name] = $this->own('hr_employee_profile', HrEmployeeProfile::query()->create([
                'user_id' => $user->id,
                'employee_number' => ItSecurityDesktopReleaseFixtureReadiness::STAFF_EMPLOYEE_NUMBERS[$name],
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
            $lastName = Str::after($name, 'RELEASE V10 Client ');
            $clients[$name] = $this->own('client', Client::query()->create([
                'site_id' => $sites[$siteName]->id,
                'first_name' => 'RELEASE V10 Client',
                'last_name' => $lastName,
                'status' => 'active',
                'service_start_date' => today()->subYear(),
            ]));
        }

        $trackingConsentType = $this->own('consent_type', ConsentType::query()->create([
            'name' => 'RELEASE V10 Client Location Tracking',
            'category' => 'optional',
            'description' => 'Non-production desktop release fixture consent only.',
            'purpose' => 'Client personal safety tracking',
            'legal_basis' => 'Approved non-production release acceptance.',
            'is_mandatory' => false,
            'requires_capacity_assessment' => false,
            'allows_withdrawal' => true,
            'validity_period_days' => 365,
            'renewal_required' => false,
            'active' => true,
        ]));
        $consents = [
            'RELEASE V10 Client Alpha' => $this->own('client_consent', ClientConsent::query()->create([
                'client_id' => $clients['RELEASE V10 Client Alpha']->id,
                'consent_type_id' => $trackingConsentType->id,
                'status' => 'given',
                'given_at' => now(),
                'given_by_user_id' => $manager->id,
                'given_by_relationship' => 'self',
                'given_method' => 'approved_non_production_fixture',
                'given_notes' => 'Owned desktop release acceptance baseline.',
                'expires_at' => now()->addYear(),
                'created_by' => $manager->id,
                'updated_by' => $manager->id,
            ])),
        ];

        $assets = [
            'RELEASE V10 Alpha Vehicle' => $this->own('asset', Asset::query()->create([
                'site_id' => $sites['RELEASE V10 Site Alpha']->id,
                'home_site_id' => $sites['RELEASE V10 Site Alpha']->id,
                'client_id' => null,
                'created_by_user_id' => $manager->id,
                'updated_by_user_id' => $manager->id,
                'asset_tag' => 'REL-VEHICLE-001',
                'qr_token' => hash('sha256', 'it-security-release-v10-vehicle'),
                'name' => 'RELEASE V10 Alpha Vehicle',
                'category' => 'Vehicle',
                'status' => 'active',
                'risk_level' => 'low',
            ])),
            'RELEASE V10 Alpha Asset' => $this->own('asset', Asset::query()->create([
                'site_id' => $sites['RELEASE V10 Site Alpha']->id,
                'client_id' => null,
                'created_by_user_id' => $manager->id,
                'updated_by_user_id' => $manager->id,
                'asset_tag' => 'REL-ASSET-001',
                'qr_token' => hash('sha256', 'it-security-release-v10-asset'),
                'name' => 'RELEASE V10 Alpha Asset',
                'category' => 'IT Equipment',
                'status' => 'active',
                'risk_level' => 'low',
            ])),
        ];

        $this->own('fin_fixed_asset', FinFixedAsset::query()->create([
            'organization_id' => 1,
            'asset_name' => 'RELEASE V10 Alpha Financial Record',
            'asset_tag' => 'REL-FIN-001',
            'category' => 'it_equipment',
            'purchase_date' => today()->subYear(),
            'purchase_cost' => 1000,
            'residual_value' => 100,
            'useful_life_months' => 36,
            'depreciation_method' => 'straight_line',
            'accumulated_depreciation' => 0,
            'status' => 'active',
            'linked_asset_id' => $assets['RELEASE V10 Alpha Asset']->id,
            'created_by' => $manager->id,
        ]));

        $devices = [];
        $trackingObservedAt = now()->subMinutes(5);
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
                'last_seen_at' => $name === 'RELEASE V10 Alpha Personal Tracker'
                    ? $trackingObservedAt
                    : now(),
                'latitude' => $name === 'RELEASE V10 Alpha Personal Tracker'
                    ? ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LATITUDE
                    : null,
                'longitude' => $name === 'RELEASE V10 Alpha Personal Tracker'
                    ? ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LONGITUDE
                    : null,
                'provider' => ($contract['release_fixture_command'] ?? false) ? 'release_fixture' : 'manual',
                'config' => ($contract['release_fixture_command'] ?? false) ? [
                    'management' => [
                        'capabilities' => ['access.door.unlock_timed'],
                        'release_fixture' => ['no_network' => true],
                    ],
                ] : null,
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
                    'consent_id' => $name === 'RELEASE V10 Alpha Personal Tracker'
                        ? $consents['RELEASE V10 Client Alpha']->id
                        : null,
                    'tracking_purpose' => $name === 'RELEASE V10 Alpha Personal Tracker'
                        ? 'Client personal safety tracking'
                        : null,
                    'authority_basis' => $name === 'RELEASE V10 Alpha Personal Tracker'
                        ? 'assignment_linked_client_consent'
                        : null,
                    'access_audience' => $name === 'RELEASE V10 Alpha Personal Tracker'
                        ? ['authorised_client_care', 'control_room', 'health_and_safety']
                        : null,
                    'retention_days' => $name === 'RELEASE V10 Alpha Personal Tracker' ? 90 : null,
                    'collection_started_at' => $name === 'RELEASE V10 Alpha Personal Tracker' ? now() : null,
                    'notes' => 'Owned desktop release acceptance assignment.',
                ]));
            }
            $devices[$name] = $device;
        }

        $this->own('integration_event', IntegrationEvent::query()->create([
            'site_id' => $sites['RELEASE V10 Site Alpha']->id,
            'room_id' => null,
            'hardware_id' => null,
            'canonical_device_id' => $devices['RELEASE V10 Alpha Personal Tracker']->id,
            'provider' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER,
            'source_app' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP,
            'source_event_id' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID,
            'occurred_at' => $trackingObservedAt,
            'received_at' => $trackingObservedAt,
            'severity' => IntegrationEvent::SEVERITY_INFO,
            'event_type' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TYPE,
            'tags' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TAGS,
            'normalized_payload' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PAYLOAD,
            'raw_payload' => null,
        ]));

        return compact('sites', 'actors', 'staff', 'profiles', 'clients', 'consents', 'assets', 'devices');
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
        $alpha = $sites['RELEASE V10 Site Alpha'];
        $requester = $actors['release-v10-requester@acceptance.invalid'];
        $manager = $actors['release-v10-it-manager@acceptance.invalid'];
        $reviewer = $actors['release-v10-it-reviewer@acceptance.invalid'];
        $switch = $devices['RELEASE V10 Alpha Switch'];

        $this->own('it_catalog_item', ItCatalogItem::query()->create([
            'name' => 'RELEASE V10 Access Request',
            'slug' => 'release-v10-access-request',
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
            'title' => 'RELEASE V10 Network Recovery',
            'slug' => 'release-v10-network-recovery',
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
            'title' => 'RELEASE V10 Requester Access Request',
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
            'title' => 'RELEASE V10 Alpha Switch Connectivity Incident',
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
            'assigned_to_user_id' => $actors['release-v10-control-room@acceptance.invalid']->id,
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
            hash('sha256', 'release-v10-alpha-switch-monitor'),
        );
        $this->records[] = ['type' => 'monitoring_incident_evidence_snapshot', 'id' => (int) $snapshot->id];

        $problemTicket = $this->workTicket('RELEASE V10 Recurring Network Problem', 'problem', $alpha, $requester, $manager);
        $this->own('it_problem', ItProblem::query()->create([
            'ticket_id' => $problemTicket->id,
            'impact_summary' => 'Intermittent release acceptance connectivity.',
            'workaround' => 'Use the secondary monitored path.',
            'created_by_user_id' => $manager->id,
            'updated_by_user_id' => $manager->id,
        ]));
        $changeTicket = $this->workTicket('RELEASE V10 Network Change', 'change', $alpha, $requester, $manager);
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
        $majorTicket = $this->workTicket('RELEASE V10 Major Connectivity Incident', 'major_incident', $alpha, $requester, $manager);
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
                'employee_profile_id' => $profiles['RELEASE V10 Staff Alpha']->id,
                'lifecycle_type' => $lifecycle,
                'source_type' => 'release_fixture',
                'source_id' => $offset + 1,
                'source_event_key' => "release-v10-desktop-{$lifecycle}",
                'status' => 'pending',
                'effective_at' => now()->addDays($offset),
                'role_snapshot' => 'support_worker',
                'site_id_snapshot' => $alpha->id,
                'employment_type_snapshot' => 'full_time',
                'changes' => $lifecycle === 'mover' ? ['site' => ['state' => 'review_required']] : null,
                'created_by_user_id' => $manager->id,
            ]));
            $this->own('it_provisioning_request', ItProvisioningRequest::query()->create([
                'employee_profile_id' => $profiles['RELEASE V10 Staff Alpha']->id,
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
            'release-v10-staff-alpha@acceptance.invalid',
            'release-v10-staff-hidden@acceptance.invalid',
        ])->exists()
            || HrEmployeeProfile::query()->whereIn('employee_number', [
                ...array_values(ItSecurityDesktopReleaseFixtureReadiness::ACTOR_EMPLOYEE_NUMBERS),
                ...array_values(ItSecurityDesktopReleaseFixtureReadiness::STAFF_EMPLOYEE_NUMBERS),
            ])->exists()
            || User::query()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::STAFF))->exists()
            || Site::withTrashed()->whereIn('name', ItSecurityDesktopReleaseFixtureReadiness::SITES)->exists()
            || Client::withTrashed()->where('first_name', 'RELEASE V10 Client')->whereIn('last_name', ['Alpha', 'Hidden'])->exists()
            || ConsentType::withTrashed()->where('name', 'RELEASE V10 Client Location Tracking')->exists()
            || ClientConsent::withTrashed()->whereHas('consentType', fn ($query) => $query->withTrashed()->where('name', 'RELEASE V10 Client Location Tracking'))->exists()
            || Asset::query()->whereIn('name', ['RELEASE V10 Alpha Vehicle', 'RELEASE V10 Alpha Asset'])->exists()
            || FinFixedAsset::withTrashed()->where('asset_name', 'RELEASE V10 Alpha Financial Record')->exists()
            || Device::withTrashed()->whereIn('name', array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES))->exists()
            || ItCatalogItem::withTrashed()->where('slug', 'release-v10-access-request')->exists()
            || ItKbArticle::query()->where('slug', 'release-v10-network-recovery')->exists()
            || ItProvisioningWorkflow::query()->whereIn('source_event_key', [
                'release-v10-desktop-joiner',
                'release-v10-desktop-mover',
                'release-v10-desktop-leaver',
            ])->exists()
            || IntegrationEvent::query()
                ->where('provider', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER)
                ->where('source_app', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP)
                ->where('source_event_id', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID)
                ->exists()
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

    private function lockedPack(): ItSecurityDesktopReleaseFixturePack
    {
        return ItSecurityDesktopReleaseFixturePack::query()
            ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Resolve only the exact mutable D10 records named in the signed fixture
     * manifest. This deliberately has no command, audit, or batch path.
     *
     * @return array{0: ClientConsent, 1: DeviceAssignment, 2: User, 3: Device, 4: IntegrationEvent, 5: Client}
     */
    private function trackingFixtureRecords(ItSecurityDesktopReleaseFixturePack $pack, bool $lock = false): array
    {
        $records = collect((array) data_get($pack->manifest, 'records', []));
        $recordIds = static function (string $type) use ($records): array {
            return $records->filter(
                fn (mixed $record): bool => is_array($record) && ($record['type'] ?? null) === $type,
            )->pluck('id')
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->values()
                ->all();
        };
        $one = static function ($query) use ($lock): ?Model {
            if ($lock) {
                $query->lockForUpdate();
            }
            $matches = $query->get();

            return $matches->count() === 1 ? $matches->first() : null;
        };

        $actor = $one(User::query()
            ->whereIn('id', $recordIds('user'))
            ->where('email', 'release-v10-control-room@acceptance.invalid'));
        $consentType = $one(ConsentType::query()
            ->whereIn('id', $recordIds('consent_type'))
            ->where('name', 'RELEASE V10 Client Location Tracking'));
        $client = $one(Client::query()
            ->whereIn('id', $recordIds('client'))
            ->where('first_name', 'RELEASE V10 Client')
            ->where('last_name', 'Alpha'));
        $device = $one(Device::query()
            ->whereIn('id', $recordIds('device'))
            ->where('name', 'RELEASE V10 Alpha Personal Tracker'));
        $consent = $client instanceof Client && $consentType instanceof ConsentType
            ? $one(ClientConsent::query()
                ->whereIn('id', $recordIds('client_consent'))
                ->where('client_id', $client->id)
                ->where('consent_type_id', $consentType->id))
            : null;
        $assignment = $consent instanceof ClientConsent && $client instanceof Client && $device instanceof Device
            ? $one(DeviceAssignment::query()
                ->whereIn('id', $recordIds('device_assignment'))
                ->where('device_id', $device->id)
                ->where('assignable_type', DeviceAssignment::TARGET_CLIENT)
                ->where('assignable_id', $client->id)
                ->where('consent_id', $consent->id)
                ->whereNull('released_at'))
            : null;
        $event = $device instanceof Device && $client instanceof Client
            ? $one(IntegrationEvent::query()
                ->whereIn('id', $recordIds('integration_event'))
                ->where('provider', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER)
                ->where('source_app', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP)
                ->where('source_event_id', ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_ID)
                ->where('canonical_device_id', $device->id)
                ->where('site_id', $client->site_id))
            : null;

        $activeConsentAssignments = collect();
        if ($consent instanceof ClientConsent) {
            $activeConsentAssignmentsQuery = DeviceAssignment::query()
                ->where('consent_id', $consent->id)
                ->whereNull('released_at');
            if ($lock) {
                $activeConsentAssignmentsQuery->lockForUpdate();
            }
            $activeConsentAssignments = $activeConsentAssignmentsQuery->get();
        }

        if (! $actor instanceof User
            || ! $consentType instanceof ConsentType
            || ! $client instanceof Client
            || ! $device instanceof Device
            || ! $consent instanceof ClientConsent
            || ! $assignment instanceof DeviceAssignment
            || ! $event instanceof IntegrationEvent
            || (int) $assignment->consent_id !== (int) $consent->id
            || (int) $consent->client_id !== (int) $client->id
            || $assignment->assignable_type !== DeviceAssignment::TARGET_CLIENT
            || (int) $assignment->assignable_id !== (int) $client->id
            || (int) $assignment->device_id !== (int) $device->id
            || $device->domain !== 'tracking'
            || $device->category !== 'personal_tracker'
            || $consentType->purpose !== 'Client personal safety tracking'
            || $activeConsentAssignments->count() !== 1
            || (int) $activeConsentAssignments->sole()->id !== (int) $assignment->id) {
            throw new DomainException(self::TRACKING_SCOPE_GAP);
        }

        return [$consent, $assignment, $actor, $device, $event, $client];
    }

    /** @return list<string> */
    private function trackingFixtureMutationGaps(ItSecurityDesktopReleaseFixturePack $pack, bool $lock = false): array
    {
        try {
            [, $assignment, , $device, $event] = $this->trackingFixtureRecords($pack, $lock);
        } catch (DomainException) {
            return [self::TRACKING_SCOPE_GAP];
        }

        $retentionDays = max(1, (int) ($assignment->retention_days ?? 90));
        $eventReady = $event->occurred_at !== null
            && $event->received_at?->equalTo($event->occurred_at) === true
            && $event->occurred_at->gte(now()->subDays($retentionDays))
            && $event->occurred_at->lte(now())
            && $event->provider === ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PROVIDER
            && $event->source_app === ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_SOURCE_APP
            && $event->event_type === ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TYPE
            && $event->severity === IntegrationEvent::SEVERITY_INFO
            && $event->room_id === null
            && $event->hardware_id === null
            && Arr::sortRecursive((array) $event->tags) === Arr::sortRecursive(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_TAGS)
            && Arr::sortRecursive((array) $event->normalized_payload) === Arr::sortRecursive(ItSecurityDesktopReleaseFixtureReadiness::TRACKING_EVENT_PAYLOAD)
            && $event->raw_payload === null
            && $device->location_description === null;

        return $eventReady ? [] : [self::TRACKING_HISTORY_GAP];
    }

    private function restoreTrackingBaseline(ItSecurityDesktopReleaseFixturePack $pack): void
    {
        [$consent, $assignment, $actor, $device, $event] = $this->trackingFixtureRecords($pack, lock: true);
        $consent->update([
            'status' => 'given',
            'given_at' => now(),
            'given_by_user_id' => $actor->id,
            'given_by_relationship' => 'self',
            'given_method' => 'approved_non_production_fixture',
            'given_notes' => 'Owned desktop release acceptance baseline.',
            'withdrawn_at' => null,
            'withdrawn_by_user_id' => null,
            'withdrawal_reason' => null,
            'withdrawal_acknowledged' => null,
            'expires_at' => now()->addYear(),
            'updated_by' => $actor->id,
        ]);
        $this->trackingPrivacy->resumeClientAssignment($assignment, $consent->fresh(), $actor->id);
        $device->forceFill([
            'latitude' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LATITUDE,
            'longitude' => ItSecurityDesktopReleaseFixtureReadiness::TRACKING_LONGITUDE,
            'last_seen_at' => $event->occurred_at,
        ])->save();
    }

    /** @return list<string> */
    private function commandFixtureMutationGaps(
        ItSecurityDesktopReleaseFixturePack $pack,
        bool $lock = false,
    ): array {
        try {
            $this->commandFixtureDevices($pack, $lock);
        } catch (DomainException) {
            return ['release_fixture_command_device_contract_mismatch'];
        }

        return [];
    }

    /** @return Collection<int, Device> */
    private function commandFixtureDevices(
        ItSecurityDesktopReleaseFixturePack $pack,
        bool $lock = false,
    ): Collection {
        $manifestDeviceIds = collect((array) data_get($pack->manifest, 'records', []))
            ->filter(fn (mixed $record): bool => is_array($record)
                && ($record['type'] ?? null) === 'device'
                && is_int($record['id'] ?? null))
            ->pluck('id')
            ->all();
        $query = Device::query()
            ->whereIn('id', $manifestDeviceIds)
            ->whereIn('name', ['RELEASE V10 Alpha Door', 'RELEASE V10 Alpha Door Secondary']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $devices = $query->get()->sortBy('name')->values();
        $contract = [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'release_fixture' => ['no_network' => true],
            ],
        ];

        if ($devices->pluck('name')->all() !== ['RELEASE V10 Alpha Door', 'RELEASE V10 Alpha Door Secondary']
            || $devices->contains(fn (Device $device): bool => $device->provider !== 'release_fixture'
                || $device->domain !== 'security'
                || $device->category !== 'access_control'
                || ($device->config ?? []) !== $contract)) {
            throw new DomainException('The release fixture command Device contract is incomplete.');
        }

        return $devices;
    }

    private function refreshCommandFixtureObservationBaseline(
        ItSecurityDesktopReleaseFixturePack $pack,
    ): void {
        $observedAt = now();
        foreach ($this->commandFixtureDevices($pack, lock: true) as $device) {
            $device->forceFill(['last_seen_at' => $observedAt])->save();
        }
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

    /** @return list<string> */
    private function retainedD16EvidenceGaps(ItSecurityDesktopReleaseFixturePack $pack): array
    {
        $deviceIds = collect((array) data_get($pack->manifest, 'records', []))
            ->filter(fn (mixed $record): bool => is_array($record)
                && ($record['type'] ?? null) === 'device'
                && is_int($record['id'] ?? null))
            ->pluck('id')
            ->values();
        if ($deviceIds->isEmpty()) {
            return [];
        }

        $hasRetainedCommand = DB::table('device_command_requests')
            ->whereIn('device_id', $deviceIds->all())
            ->exists();
        $hasRetainedBatch = DB::table('device_command_batch_targets')
            ->whereIn('device_id', $deviceIds->all())
            ->exists();

        return ($hasRetainedCommand || $hasRetainedBatch)
            ? ['release_fixture_retained_d16_evidence_requires_pack_archive']
            : [];
    }

    /** @return list<string> */
    private function pendingCleanupGaps(ItSecurityDesktopReleaseFixturePack $pack): array
    {
        $manifest = $pack->manifest;
        if ($pack->pack_key !== ItSecurityDesktopReleaseFixturePack::PACK_KEY
            || $pack->state !== ItSecurityDesktopReleaseFixturePack::STATE_CLEANUP_FILES_PENDING
            || ! is_array($manifest)
            || ! hash_equals((string) $pack->manifest_sha256, $this->manifestHash($manifest))
            || ! $this->manifestShapeValid($manifest)) {
            return ['release_fixture_cleanup_journal_integrity_failed'];
        }

        $disk = Storage::disk(ItAttachment::DISK);
        foreach ($manifest['files'] as $file) {
            try {
                if ($disk->exists($file['path'])
                    && ! hash_equals($file['sha256'], hash('sha256', (string) $disk->get($file['path'])))) {
                    return ['release_fixture_owned_file_mismatch'];
                }
            } catch (Throwable) {
                return ['release_fixture_owned_file_mismatch'];
            }
        }

        return [];
    }

    private function finishPendingCleanupFiles(): void
    {
        $pack = ItSecurityDesktopReleaseFixturePack::query()
            ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
            ->firstOrFail();
        $gaps = $this->pendingCleanupGaps($pack);
        if ($gaps !== []) {
            throw new DomainException('The release fixture cleanup journal failed its integrity check.');
        }

        $disk = Storage::disk(ItAttachment::DISK);
        foreach ($pack->manifest['files'] as $file) {
            if ($disk->exists($file['path']) && ! $disk->delete($file['path'])) {
                throw new DomainException('An owned release fixture attachment could not be removed.');
            }
        }

        DB::transaction(function (): void {
            $pack = ItSecurityDesktopReleaseFixturePack::query()
                ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            if ($this->pendingCleanupGaps($pack) !== []) {
                throw new DomainException('The release fixture cleanup journal failed its integrity check.');
            }
            foreach ($pack->manifest['files'] as $file) {
                if (Storage::disk(ItAttachment::DISK)->exists($file['path'])) {
                    throw new DomainException('An owned release fixture attachment remains after cleanup.');
                }
            }
            $pack->delete();
        }, 1);
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

    /**
     * Remove browser-created D01 records that are necessarily outside the
     * prepare-time manifest. Both fixture parent IDs come from that manifest;
     * no display-name, time-window, or broad fixture-label selector is used.
     *
     * Audit rows deliberately remain immutable release history.
     */
    private function deleteD01JourneyRecords(ItSecurityDesktopReleaseFixturePack $pack): void
    {
        $manifest = (array) $pack->manifest;
        $records = collect((array) data_get($manifest, 'records', []));
        $catalogIds = $records
            ->filter(fn (mixed $record): bool => is_array($record) && ($record['type'] ?? null) === 'it_catalog_item')
            ->pluck('id');
        $requesterIds = $records
            ->filter(fn (mixed $record): bool => is_array($record) && ($record['type'] ?? null) === 'user')
            ->pluck('id');
        $catalog = ItCatalogItem::query()
            ->whereIn('id', $catalogIds)
            ->where('slug', 'release-v10-access-request')
            ->first();
        $requester = User::query()
            ->whereIn('id', $requesterIds)
            ->where('email', 'release-v10-requester@acceptance.invalid')
            ->first();
        if (! $catalog || ! $requester) {
            throw new DomainException('The owned release fixture manifest is missing the D01 parent identities.');
        }

        $ticketType = (new ItTicket)->getMorphClass();
        $submissions = ItCatalogSubmission::query()
            ->where('catalog_item_id', $catalog->id)
            ->where('requester_user_id', $requester->id)
            ->where('result_type', $ticketType)
            ->get(['id', 'result_id']);
        $ticketIds = $submissions->pluck('result_id')->map(fn (mixed $id): int => (int) $id)->unique()->values();
        if ($ticketIds->isEmpty()) {
            return;
        }

        $tickets = ItTicket::query()
            ->whereIn('id', $ticketIds)
            ->where('requester_user_id', $requester->id)
            ->get(['id']);
        if ($tickets->count() !== $ticketIds->count()) {
            throw new DomainException('The D01 catalogue submission result no longer matches its owned requester ticket.');
        }

        $ticketIds = $tickets->pluck('id')->values();
        $commentIds = ItTicketComment::query()
            ->whereIn('ticket_id', $ticketIds)
            ->pluck('id');
        $attachmentQuery = ItAttachment::query()->where(function ($query) use ($ticketIds, $commentIds): void {
            $query->where(function ($tickets) use ($ticketIds): void {
                $tickets->where('attachable_type', (new ItTicket)->getMorphClass())
                    ->whereIn('attachable_id', $ticketIds);
            });
            if ($commentIds->isNotEmpty()) {
                $query->orWhere(function ($comments) use ($commentIds): void {
                    $comments->where('attachable_type', (new ItTicketComment)->getMorphClass())
                        ->whereIn('attachable_id', $commentIds);
                });
            }
        });
        $attachments = $attachmentQuery->get(['id', 'path']);
        if ($attachments->isNotEmpty()) {
            throw new DomainException('D01 release acceptance does not permit private attachments.');
        }

        $deliveryIds = ItEmailDelivery::query()
            ->where(function ($query) use ($ticketIds, $commentIds): void {
                $query->whereIn('it_ticket_id', $ticketIds);
                if ($commentIds->isNotEmpty()) {
                    $query->orWhereIn('it_ticket_comment_id', $commentIds);
                }
            })
            ->pluck('id');
        do {
            $retryIds = $deliveryIds->isEmpty()
                ? collect()
                : ItEmailDelivery::query()->whereIn('retry_of_delivery_id', $deliveryIds)->pluck('id');
            $newIds = $retryIds->diff($deliveryIds);
            $deliveryIds = $deliveryIds->merge($newIds)->unique()->values();
        } while ($newIds->isNotEmpty());

        ItEmailDelivery::query()->whereIn('id', $deliveryIds)->delete();
        ItTicketEvent::query()
            ->where('subject_type', $ticketType)
            ->whereIn('subject_id', $ticketIds)
            ->delete();
        ItCatalogSubmission::query()->whereIn('id', $submissions->pluck('id'))->delete();
        ItTicket::query()->whereIn('id', $ticketIds)->delete();

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
            'action' => in_array($action, ItSecurityDesktopReleaseFixtureMutationGuard::ACTIONS, true) ? $action : null,
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
