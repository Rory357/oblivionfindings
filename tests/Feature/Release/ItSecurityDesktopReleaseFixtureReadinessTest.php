<?php

use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketLinkService;
use App\Domain\Monitoring\Models\MonitoringIncidentEvidenceSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssetLink;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\ItAttachment;
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

it('checks effective actor permissions in addition to the role label', function (): void {
    $site = Site::factory()->create(['name' => 'RELEASE Site Alpha']);
    $actor = User::factory()->create([
        'email' => 'release-requester@acceptance.invalid',
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
    $site = Site::factory()->create(['name' => 'RELEASE Site Alpha']);
    $actor = User::factory()->create();
    $gateway = Device::factory()->create([
        'name' => 'RELEASE Alpha Gateway',
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
        'name' => 'RELEASE Alpha Gateway',
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

it('requires unique canonical Asset and Finance owners with exact Alpha scope', function (): void {
    $alpha = Site::factory()->create(['name' => 'RELEASE Site Alpha']);
    $other = Site::factory()->create(['name' => 'Other Site']);
    $actor = User::factory()->create();
    $vehicle = Asset::factory()->create([
        'name' => 'RELEASE Alpha Vehicle',
        'category' => 'Vehicle',
        'site_id' => $alpha->id,
        'home_site_id' => $other->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    $asset = Asset::factory()->create([
        'name' => 'RELEASE Alpha Asset',
        'category' => 'Safety Equipment',
        'site_id' => $alpha->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    $financial = FinFixedAsset::factory()->create([
        'asset_name' => 'RELEASE Alpha Financial Record',
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
        'name' => 'RELEASE Alpha Asset',
        'category' => 'IT Equipment',
        'site_id' => $alpha->id,
        'client_id' => null,
        'status' => 'active',
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
    ]);
    FinFixedAsset::factory()->create([
        'asset_name' => 'RELEASE Alpha Financial Record',
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
    $alpha = Site::factory()->create(['name' => 'RELEASE Site Alpha']);
    $manager = User::factory()->create(['email' => 'release-it-manager@acceptance.invalid']);
    $switch = Device::factory()->create([
        'name' => 'RELEASE Alpha Switch',
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
