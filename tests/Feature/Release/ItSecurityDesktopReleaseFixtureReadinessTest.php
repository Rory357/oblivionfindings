<?php

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Management\Services\CommandObservationFreshnessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\ItAttachment;
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
use App\Support\Release\ItSecurityDesktopReleaseFixtureReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('fails closed with a value-free report when the deployed fixture pack is absent', function (): void {
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower(ltrim((string) $query->sql));
    });

    $exit = Artisan::call('it-security:verify-desktop-release-fixtures', ['--json' => true]);
    $reportJson = trim(Artisan::output());
    $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report)->toMatchArray([
            'schema_version' => 1,
            'evidence_class' => ItSecurityDesktopReleaseFixtureReadiness::EVIDENCE_CLASS,
            'state' => 'not_ready',
            'v10_release_evidence' => false,
        ])
        ->and(array_keys($report['sections']))->toBe([
            'sites',
            'actors',
            'people',
            'devices',
            'assets',
            'it_and_control_room',
            'runtime',
        ])
        ->and($report['gap_codes'])->toContain(
            'release_sites_missing',
            'release_actor_missing',
            'release_client_missing',
            'release_staff_missing',
            'release_device_missing',
            'release_vehicle_missing',
            'release_catalog_fixture_missing',
            'release_control_room_fixture_missing',
            'release_fixture_runtime_not_approved',
            'release_fixture_runtime_revision_invalid',
            'release_fixture_runtime_pack_missing',
        )
        ->and($report['gap_codes'])->not->toContain('fixture_readiness_query_failed');

    foreach ([
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::ACTORS),
        ...ItSecurityDesktopReleaseFixtureReadiness::SITES,
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::CLIENTS),
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::STAFF),
        ...array_keys(ItSecurityDesktopReleaseFixtureReadiness::DEVICES),
    ] as $protectedFixtureLabel) {
        expect($reportJson)->not->toContain($protectedFixtureLabel);
    }

    expect(collect($statements)->filter(
        fn (string $statement): bool => preg_match(
            '/^(insert|update|delete|replace|create|alter|drop|truncate)\b/',
            $statement,
        ) === 1,
    )->all())->toBe([]);
});

it('requires the active ready pack configured revision and deployed checkout together for runtime readiness', function (): void {
    $revision = str_repeat('a', 40);
    config()->set('it.desktop_release_fixtures.enabled', true);
    config()->set('it.desktop_release_fixtures.environment_class', 'approved_non_production');
    config()->set('it.desktop_release_fixtures.release_revision', $revision);
    ItSecurityDesktopReleaseFixturePack::query()->create([
        'pack_key' => ItSecurityDesktopReleaseFixturePack::PACK_KEY,
        'release_revision' => $revision,
        'state' => ItSecurityDesktopReleaseFixturePack::STATE_READY,
        'manifest' => ['records' => [], 'files' => [], 'schema_version' => 1],
        'manifest_sha256' => str_repeat('a', 64),
        'prepared_at' => now(),
        'last_verified_at' => now(),
    ]);

    $readiness = new ItSecurityDesktopReleaseFixtureReadiness(
        app(CanonicalDeviceSiteResolver::class),
        app(CommandObservationFreshnessService::class),
        fn (): string => 'staging',
        fn (string $checkout, string $candidate): bool => $candidate === $revision,
    );

    $readyRuntime = $readiness->assess(requireRuntimePack: true);
    config()->set('it.desktop_release_fixtures.release_revision', str_repeat('b', 40));
    $mismatchedRuntime = $readiness->assess(requireRuntimePack: true);

    expect($readyRuntime['sections']['runtime'])->toMatchArray([
        'required' => 5,
        'ready' => 5,
        'gap_codes' => [],
    ])->and($mismatchedRuntime['gap_codes'])->toContain('release_fixture_runtime_pack_revision_mismatch');
});

it('checks effective actor permissions in addition to the role label', function (): void {
    $site = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $actor = User::factory()->create([
        'email' => 'release-v10-requester@acceptance.invalid',
        'role' => 'support_worker',
    ]);
    $role = Role::query()->create([
        'name' => 'support_worker',
        'label' => 'Support Worker',
        'level' => 10,
        'type' => 'system',
    ]);
    $itRequest = Permission::query()->create([
        'key' => 'it.request',
        'description' => 'Raise and track your own IT tickets',
        'group' => 'it',
        'module' => 'Operations',
    ]);
    $itView = Permission::query()->create([
        'key' => 'it.view',
        'description' => 'View IT work',
        'group' => 'it',
        'module' => 'Operations',
    ]);

    $role->permissions()->attach($itRequest);
    $actor->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'employee_number' => ItSecurityDesktopReleaseFixtureReadiness::ACTOR_EMPLOYEE_NUMBERS['release-v10-requester@acceptance.invalid'],
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subDay(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    $allowed = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($allowed['sections']['actors']['ready'])->toBe(1)
        ->and($allowed['gap_codes'])->not->toContain(
            'release_actor_required_permission_missing',
            'release_actor_forbidden_permission_present',
        );

    $role->permissions()->attach($itView);

    $overPrivileged = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($overPrivileged['sections']['actors']['ready'])->toBe(0)
        ->and($overPrivileged['gap_codes'])->toContain('release_actor_forbidden_permission_present')
        ->and($overPrivileged['gap_codes'])->not->toContain('release_actor_required_permission_missing');
});

it('requires unique Devices with exact taxonomy and canonical owner bindings', function (): void {
    $site = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $actor = User::factory()->create();
    $gateway = Device::factory()->create([
        'name' => 'RELEASE V10 Alpha Gateway',
        'domain' => 'security',
        'category' => 'cctv',
        'subcategory' => 'dome_camera',
    ]);

    $invalid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($invalid['sections']['devices']['ready'])->toBe(0)
        ->and($invalid['gap_codes'])->toContain(
            'release_device_taxonomy_mismatch',
            'release_device_owner_binding_mismatch',
            'release_device_canonical_scope_mismatch',
        );

    $gateway->update([
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'subcategory' => 'router',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $gateway->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);

    $valid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($valid['sections']['devices']['ready'])->toBe(1)
        ->and($valid['gap_codes'])->not->toContain(
            'release_device_taxonomy_mismatch',
            'release_device_owner_binding_mismatch',
            'release_device_canonical_scope_mismatch',
            'release_device_name_not_unique',
        );

    $unexpectedAsset = Asset::factory()->create([
        'site_id' => $site->id,
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    $unexpectedLink = DeviceAssetLink::query()->create([
        'device_id' => $gateway->id,
        'asset_id' => $unexpectedAsset->id,
        'link_type' => 'installed_in',
        'linked_at' => now(),
        'linked_by_user_id' => $actor->id,
    ]);

    $dualBound = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($dualBound['gap_codes'])->toContain('release_device_owner_binding_mismatch');

    $unexpectedLink->update(['unlinked_at' => now()]);

    $duplicate = Device::factory()->create([
        'name' => 'RELEASE V10 Alpha Gateway',
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'subcategory' => 'router',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $duplicate->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $actor->id,
    ]);

    $duplicated = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($duplicated['gap_codes'])->toContain('release_device_name_not_unique');
});

it('requires both Alpha command doors to use the exact simulated no-network fixture contract', function (): void {
    $site = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $actor = User::factory()->create();
    $contract = [
        'domain' => 'security',
        'category' => 'access_control',
        'subcategory' => 'card_reader',
        'provider' => 'release_fixture',
        'config' => [
            'management' => [
                'capabilities' => ['access.door.unlock_timed'],
                'release_fixture' => ['no_network' => true],
            ],
        ],
    ];
    $primary = Device::factory()->create([
        ...$contract,
        'name' => 'RELEASE V10 Alpha Door',
        'last_seen_at' => now(),
    ]);
    $secondary = Device::factory()->create([
        ...$contract,
        'name' => 'RELEASE V10 Alpha Door Secondary',
        'provider' => 'manual',
        'last_seen_at' => now(),
    ]);
    foreach ([$primary, $secondary] as $device) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $actor->id,
        ]);
    }

    $invalid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();
    expect($invalid['sections']['devices']['ready'])->toBe(1)
        ->and($invalid['gap_codes'])->toContain('release_fixture_command_device_contract_mismatch');

    $secondary->update(['provider' => 'release_fixture']);
    $valid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();
    expect($valid['sections']['devices']['ready'])->toBe(2)
        ->and($valid['gap_codes'])->not->toContain(
            'release_fixture_command_device_contract_mismatch',
            'release_fixture_command_device_set_mismatch',
        );

    $primary->update(['last_seen_at' => now()->subHour()]);
    $stale = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();
    expect($stale['sections']['devices']['ready'])->toBe(1)
        ->and($stale['gap_codes'])->toContain('release_fixture_command_observation_stale');
    $primary->update(['last_seen_at' => now()]);

    Device::factory()->create([
        ...$contract,
        'name' => 'RELEASE V10 Unowned release fixture lookalike',
    ]);
    $unexpected = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();
    expect($unexpected['gap_codes'])->toContain('release_fixture_command_device_set_mismatch');
});

it('requires unique canonical Asset and Finance owners with exact Alpha scope', function (): void {
    $alpha = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $other = Site::factory()->create(['name' => 'Other Site']);
    $actor = User::factory()->create();
    $vehicle = Asset::factory()->create([
        'name' => 'RELEASE V10 Alpha Vehicle',
        'category' => 'Vehicle',
        'site_id' => $alpha->id,
        'home_site_id' => $other->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    $asset = Asset::factory()->create([
        'name' => 'RELEASE V10 Alpha Asset',
        'category' => 'Safety Equipment',
        'site_id' => $alpha->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    $financial = FinFixedAsset::factory()->create([
        'asset_name' => 'RELEASE V10 Alpha Financial Record',
        'category' => 'vehicle',
        'status' => 'active',
        'linked_asset_id' => $vehicle->id,
    ]);

    $invalid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($invalid['sections']['assets'])->toMatchArray([
        'required' => 3,
        'present' => 3,
        'ready' => 0,
    ])->and($invalid['gap_codes'])->toContain(
        'release_vehicle_scope_mismatch',
        'release_asset_scope_mismatch',
        'release_financial_record_link_mismatch',
    );

    $vehicle->update(['home_site_id' => $alpha->id]);
    $asset->update(['category' => 'IT Equipment']);
    $financial->update([
        'category' => 'it_equipment',
        'linked_asset_id' => $asset->id,
    ]);

    $valid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($valid['sections']['assets']['ready'])->toBe(3)
        ->and($valid['gap_codes'])->not->toContain(
            'release_vehicle_scope_mismatch',
            'release_asset_scope_mismatch',
            'release_financial_record_link_mismatch',
            'release_asset_name_not_unique',
            'release_financial_record_name_not_unique',
        );

    Asset::factory()->create([
        'name' => 'RELEASE V10 Alpha Asset',
        'category' => 'IT Equipment',
        'site_id' => $alpha->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    FinFixedAsset::factory()->create([
        'asset_name' => 'RELEASE V10 Alpha Financial Record',
        'category' => 'it_equipment',
        'status' => 'active',
        'linked_asset_id' => $asset->id,
    ]);

    $duplicated = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($duplicated['sections']['assets']['ready'])->toBe(1)
        ->and($duplicated['gap_codes'])->toContain(
            'release_asset_name_not_unique',
            'release_financial_record_name_not_unique',
        );
});

it('binds the IT incident Control Room alert and immutable evidence to the exact Alpha Switch', function (): void {
    $alpha = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $manager = User::factory()->create(['email' => 'release-v10-it-manager@acceptance.invalid']);
    $switch = Device::factory()->create([
        'name' => 'RELEASE V10 Alpha Switch',
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'subcategory' => 'switch',
    ]);
    $decoy = Device::factory()->create([
        'name' => 'Unrelated Device',
        'domain' => 'it_infrastructure',
        'category' => 'network',
        'subcategory' => 'switch',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $switch->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $alpha->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $manager->id,
    ]);
    $alert = ControlRoomAlert::factory()->create([
        'source' => 'oblivion_monitoring',
        'site_id' => $alpha->id,
        'status' => ControlRoomAlert::STATUS_OPEN,
    ]);
    $event = DeviceEvent::query()->create([
        'device_id' => $switch->id,
        'event_type' => 'offline',
        'severity' => 'high',
        'source' => 'oblivion_monitoring',
        'occurred_at' => now(),
        'payload' => ['message' => 'Value-free release fixture evidence.'],
    ]);
    $incident = ItTicket::factory()->create([
        'site_id' => $alpha->id,
        'source' => 'system',
        'work_type' => 'incident',
        'is_organisation_wide' => false,
        'assigned_to_user_id' => $manager->id,
    ]);
    ItTicketComment::factory()->create([
        'ticket_id' => $incident->id,
        'author_user_id' => $manager->id,
        'is_internal' => false,
    ]);
    ItTicketComment::factory()->internal()->create([
        'ticket_id' => $incident->id,
        'author_user_id' => $manager->id,
    ]);
    ItAttachment::query()->create([
        'attachable_type' => $incident->getMorphClass(),
        'attachable_id' => $incident->id,
        'path' => 'release/fixture-evidence.txt',
        'original_name' => 'fixture-evidence.txt',
        'mime' => 'text/plain',
        'size' => 1,
        'uploaded_by' => $manager->id,
    ]);
    $incident->watchers()->attach($manager->id);
    ItWorkTask::factory()->create(['ticket_id' => $incident->id]);
    ItTicketApproval::query()->create([
        'it_ticket_id' => $incident->id,
        'requested_by' => $manager->id,
        'approver_id' => $manager->id,
        'status' => 'approved',
        'decided_at' => now(),
    ]);
    $linkContext = [
        'system_principal' => ItTicketLinkService::MONITORING_PRINCIPAL,
        'operation' => ItTicketLinkService::MONITORING_OPERATION,
        'site_id' => $alpha->id,
    ];
    $deviceLink = ItTicketLink::query()->create([
        'ticket_id' => $incident->id,
        'relationship' => 'affected_device',
        'linkable_type' => $switch->getMorphClass(),
        'linkable_id' => $switch->id,
        'context' => $linkContext,
    ]);
    ItTicketLink::query()->create([
        'ticket_id' => $incident->id,
        'relationship' => 'source_alert',
        'linkable_type' => $alert->getMorphClass(),
        'linkable_id' => $alert->id,
        'context' => $linkContext,
    ]);
    $snapshotPayload = ['evidence_class' => 'release_fixture_test'];
    MonitoringIncidentEvidenceSnapshot::query()->create([
        'control_room_alert_id' => $alert->id,
        'it_ticket_id' => $incident->id,
        'device_id' => $switch->id,
        'device_event_id' => $event->id,
        'site_id' => $alpha->id,
        'evidence_version' => 1,
        'captured_at' => now(),
        'snapshot' => $snapshotPayload,
        'checksum' => MonitoringIncidentEvidenceSnapshot::checksumFor($snapshotPayload),
    ]);

    $valid = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($valid['gap_codes'])->not->toContain(
        'release_incident_fixture_missing',
        'release_correlation_fixture_missing',
        'release_control_room_fixture_missing',
    );

    $deviceLink->update(['linkable_id' => $decoy->id]);

    $unrelated = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($unrelated['gap_codes'])->toContain(
        'release_incident_fixture_missing',
        'release_correlation_fixture_missing',
        'release_control_room_fixture_missing',
    );
});

it('rejects duplicate canonical Site Client and staff identities', function (): void {
    $alpha = Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);
    $hidden = Site::factory()->create(['name' => 'RELEASE V10 Site Hidden']);
    Site::factory()->create(['name' => 'RELEASE V10 Site Alpha']);

    foreach ([
        ['first_name' => 'RELEASE V10 Client', 'last_name' => 'Alpha', 'site_id' => $alpha->id],
        ['first_name' => 'RELEASE V10 Client', 'last_name' => 'Hidden', 'site_id' => $hidden->id],
        ['first_name' => 'RELEASE V10 Client', 'last_name' => 'Alpha', 'site_id' => $alpha->id],
    ] as $client) {
        Client::factory()->create([...$client, 'status' => 'active']);
    }

    foreach ([
        ['name' => 'RELEASE V10 Staff Alpha', 'site' => $alpha],
        ['name' => 'RELEASE V10 Staff Hidden', 'site' => $hidden],
        ['name' => 'RELEASE V10 Staff Alpha', 'site' => $alpha],
    ] as $staff) {
        $user = User::factory()->create(['name' => $staff['name']]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $staff['site']->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subDay(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    $report = app(ItSecurityDesktopReleaseFixtureReadiness::class)->assess();

    expect($report['gap_codes'])->toContain(
        'release_site_name_not_unique',
        'release_client_name_not_unique',
        'release_staff_name_not_unique',
    );
});
