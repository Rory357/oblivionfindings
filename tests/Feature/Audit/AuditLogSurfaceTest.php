<?php

namespace Tests\Feature\Audit;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_access_management_permission_does_not_grant_audit_pages_or_csv(): void
    {
        $settingsManager = $this->userWithPermissions(['settings.access.manage']);
        $hrSettingsManager = $this->userWithPermissions(['hr.settings.manage']);

        $this->actingAs($settingsManager)->get('/audit-logs')->assertForbidden();
        $this->actingAs($settingsManager)->get('/settings/audit-logs')->assertForbidden();
        $this->actingAs($settingsManager)->get('/settings/audit-logs/export')->assertForbidden();
        $this->actingAs($hrSettingsManager)->get('/hr/settings/audit-log')->assertForbidden();
    }

    public function test_audit_view_any_remains_application_wide_and_both_pages_share_bounded_events(): void
    {
        $firstSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $secondSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $firstClient = Client::factory()->create(['site_id' => $firstSite->id]);
        $secondClient = Client::factory()->create(['site_id' => $secondSite->id]);
        $viewer = $this->userWithPermissions(['audit.viewAny'], ['name' => 'Application Auditor']);

        AuditLog::query()->delete();
        $operations = AuditLog::create([
            'user_id' => $viewer->id,
            'client_id' => $firstClient->id,
            'action' => 'clients.profile.updated',
            'meta' => ['before' => ['status' => 'draft'], 'after' => ['status' => 'active']],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Sensitive browser fingerprint',
        ]);
        $it = AuditLog::create([
            'user_id' => $viewer->id,
            'client_id' => $secondClient->id,
            'action' => 'it.change.updated',
            'meta' => [
                'fields' => ['status', 'password'],
                'before' => ['status' => 'planned', 'password' => 'before-secret'],
                'after' => ['status' => 'approved', 'password' => 'after-secret'],
            ],
            'ip_address' => '203.0.113.11',
            'user_agent' => 'Another browser fingerprint',
        ]);
        $device = AuditLog::create(['action' => 'device.update']);
        $monitoring = AuditLog::create(['action' => 'monitoring.observation.created']);
        $general = AuditLog::create(['action' => 'custom.unknown.event']);

        $generalProps = $this->actingAs($viewer)->get('/audit-logs')->assertOk()->inertiaProps();
        $settingsProps = $this->actingAs($viewer)->get('/settings/audit-logs')->assertOk()->inertiaProps();

        $expectedIds = [$general->id, $monitoring->id, $device->id, $it->id, $operations->id];
        $this->assertSame($expectedIds, collect($generalProps['logs']['data'])->pluck('id')->all());
        $this->assertSame($expectedIds, collect($settingsProps['events']['data'])->pluck('id')->all());

        $generalEvent = collect($generalProps['logs']['data'])->firstWhere('id', $it->id);
        $settingsEvent = collect($settingsProps['events']['data'])->firstWhere('id', $it->id);
        $this->assertSame('it', $generalEvent['module']);
        $this->assertSame($generalEvent, $settingsEvent);
        $this->assertSame(['status'], $settingsEvent['properties']['fields']);
        $this->assertSame(['status' => 'planned'], $settingsEvent['properties']['before']);
        $this->assertSame(['status' => 'approved'], $settingsEvent['properties']['after']);
        $this->assertArrayNotHasKey('email', $settingsEvent['actor']);
        foreach (['meta', 'ip_address', 'user_agent'] as $sensitiveKey) {
            $this->assertArrayNotHasKey($sensitiveKey, $generalEvent);
            $this->assertArrayNotHasKey($sensitiveKey, $settingsEvent);
        }
        $encodedEvents = json_encode([$generalEvent, $settingsEvent], JSON_THROW_ON_ERROR);
        foreach (['before-secret', 'after-secret', '203.0.113.11', 'browser fingerprint'] as $sentinel) {
            $this->assertStringNotContainsString(strtolower($sentinel), strtolower($encodedEvents));
        }

        $modules = collect($settingsProps['events']['data'])
            ->mapWithKeys(fn (array $event): array => [$event['id'] => $event['module']]);
        $this->assertSame('security_devices', $modules[$device->id]);
        $this->assertSame('monitoring', $modules[$monitoring->id]);
        $this->assertSame('default', $modules[$general->id]);

        $generalItIds = collect($this->actingAs($viewer)->get('/audit-logs?module=it')->assertOk()->inertiaProps('logs.data'))->pluck('id')->all();
        $settingsItProps = $this->actingAs($viewer)
            ->get('/settings/audit-logs?module=it')
            ->assertOk()
            ->inertiaProps();
        $settingsItIds = collect($settingsItProps['events']['data'])->pluck('id')->all();
        $this->assertSame([$it->id], $generalItIds);
        $this->assertSame($generalItIds, $settingsItIds);
        $this->assertSame([
            'today' => 1,
            'this_week' => 1,
            'this_month' => 1,
        ], $settingsItProps['stats']);

        $defaultIds = collect($this->actingAs($viewer)->get('/settings/audit-logs?module=default')->assertOk()->inertiaProps('events.data'))->pluck('id')->all();
        $this->assertSame([$general->id], $defaultIds);
    }

    public function test_settings_csv_uses_the_canonical_filter_and_neutralises_formula_cells_without_ip_or_meta(): void
    {
        $viewer = $this->userWithPermissions(['audit.viewAny'], ['name' => '=2+3']);
        AuditLog::query()->delete();
        AuditLog::create([
            'user_id' => $viewer->id,
            'action' => 'it.change.updated',
            'meta' => ['secret' => 'must-not-export'],
            'ip_address' => '203.0.113.90',
            'user_agent' => 'must-not-export-agent',
        ]);
        AuditLog::create(['action' => 'finance.invoice.created']);

        $response = $this->actingAs($viewer)->get('/settings/audit-logs/export?module=it');
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();

        $this->assertStringContainsString('Timestamp,User,Description,Action,Module,Subject', $csv);
        $this->assertStringContainsString("'=2+3", $csv);
        $this->assertStringContainsString('it.change.updated', $csv);
        $this->assertStringNotContainsString('finance.invoice.created', $csv);
        $this->assertStringNotContainsString('IP Address', $csv);
        $this->assertStringNotContainsString('203.0.113.90', $csv);
        $this->assertStringNotContainsString('must-not-export', $csv);
    }

    public function test_real_hr_actions_share_one_module_contract_and_the_hr_page_is_bounded(): void
    {
        $viewer = $this->userWithPermissions([
            'audit.viewAny',
            'hr.settings.manage',
        ]);
        AuditLog::query()->delete();
        $intake = AuditLog::query()->create([
            'user_id' => $viewer->id,
            'action' => 'user.employee_intake',
            'auditable_type' => HrEmployeeProfile::class,
            'auditable_id' => 11,
            'meta' => ['before' => ['status' => 'draft'], 'after' => ['status' => 'active']],
            'ip_address' => '203.0.113.51',
            'user_agent' => 'private-hr-agent',
        ]);
        $attendance = AuditLog::query()->create([
            'action' => 'attendance.clockOut.forced',
            'auditable_type' => 'App\\Domain\\Hr\\Models\\HrAttendanceRecord',
            'auditable_id' => 12,
        ]);
        $onboarding = AuditLog::query()->create([
            'action' => 'onboardingchecklist.tasks_reordered',
            'auditable_type' => 'App\\Domain\\Hr\\Models\\HrOnboardingChecklist',
            'auditable_id' => 13,
        ]);
        $settings = AuditLog::query()->create([
            'action' => 'settings.role.updated',
        ]);

        $hrEvents = $this->actingAs($viewer)
            ->get('/settings/audit-logs?module=hr')
            ->assertOk()
            ->inertiaProps('events.data');
        $this->assertSame(
            [$onboarding->id, $attendance->id, $intake->id],
            collect($hrEvents)->pluck('id')->all(),
        );
        $this->assertSame(['hr'], collect($hrEvents)->pluck('module')->unique()->values()->all());

        $settingsIds = collect($this->get('/settings/audit-logs?module=settings')
            ->assertOk()
            ->inertiaProps('events.data'))
            ->pluck('id')
            ->all();
        $this->assertSame([$settings->id], $settingsIds);

        $hrPage = $this->get('/hr/settings/audit-log')
            ->assertOk()
            ->inertiaProps('logs.data');
        $this->assertSame(
            [$onboarding->id, $attendance->id, $intake->id],
            collect($hrPage)->pluck('id')->all(),
        );
        $encoded = json_encode($hrPage, JSON_THROW_ON_ERROR);
        foreach (['203.0.113.51', 'private-hr-agent'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $encoded);
        }
    }

    public function test_client_and_incident_evidence_exports_reject_forged_foreign_site_objects(): void
    {
        $allowedSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $foreignIncident = ClientIncident::factory()->create([
            'client_id' => $foreignClient->id,
            'site_id' => $foreignSite->id,
        ]);
        $viewer = $this->userWithPermissions([
            'audit.viewAny',
            'clients.viewAny',
            'incidents.viewAny',
            'incidents.export',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'end_date' => null,
        ]);

        $this->actingAs($viewer)
            ->get("/audit-exports/clients/{$foreignClient->id}")
            ->assertForbidden();
        $this->actingAs($viewer)
            ->get("/audit-exports/incidents/{$foreignIncident->id}")
            ->assertForbidden();

        $auditOnly = $this->userWithPermissions(['audit.viewAny']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $auditOnly->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'end_date' => null,
        ]);

        $this->actingAs($auditOnly)
            ->get("/audit-exports/clients/{$foreignClient->id}")
            ->assertForbidden();
        $this->actingAs($auditOnly)
            ->get("/audit-exports/incidents/{$foreignIncident->id}")
            ->assertForbidden();
    }

    public function test_evidence_exports_include_only_the_authorized_incident_graph(): void
    {
        $allowedSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $foreignSite = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
        $client = Client::factory()->create(['site_id' => $allowedSite->id]);
        $incident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $allowedSite->id,
        ]);
        $driftedIncident = ClientIncident::factory()->create([
            'client_id' => $client->id,
            'site_id' => $foreignSite->id,
        ]);
        $viewer = $this->userWithPermissions([
            'audit.viewAny',
            'clients.viewAny',
            'incidents.viewAny',
            'incidents.export',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $incidentAudit = AuditLog::query()->create([
            'client_id' => $client->id,
            'action' => 'incident.updated',
            'auditable_type' => $incident->getMorphClass(),
            'auditable_id' => $incident->id,
        ]);
        $unrelatedClientAudit = AuditLog::query()->create([
            'client_id' => $client->id,
            'action' => 'client.profile.updated',
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
        ]);

        $incidentResponse = $this->actingAs($viewer)
            ->get("/audit-exports/incidents/{$incident->id}")
            ->assertOk();
        $incidentZip = new \ZipArchive;
        $this->assertTrue($incidentZip->open($incidentResponse->baseResponse->getFile()->getPathname()));
        $incidentAuditRows = json_decode(
            (string) $incidentZip->getFromName('audit_logs.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $incidentZip->close();
        $incidentAuditIds = collect($incidentAuditRows)->pluck('id')->all();
        $this->assertContains($incidentAudit->id, $incidentAuditIds);
        $this->assertNotContains($unrelatedClientAudit->id, $incidentAuditIds);
        $this->assertTrue(collect($incidentAuditRows)->every(
            fn (array $row): bool => $row['auditable_type'] === $incident->getMorphClass()
                && (int) $row['auditable_id'] === (int) $incident->id,
        ));

        $clientResponse = $this->get("/audit-exports/clients/{$client->id}")->assertOk();
        $clientZip = new \ZipArchive;
        $this->assertTrue($clientZip->open($clientResponse->baseResponse->getFile()->getPathname()));
        $incidentRows = json_decode(
            (string) $clientZip->getFromName('incidents.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $clientZip->close();
        $this->assertSame([$incident->id], collect($incidentRows)->pluck('id')->all());
        $this->assertNotContains($driftedIncident->id, collect($incidentRows)->pluck('id')->all());
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'support_worker',
            'approved_at' => now(),
        ], $attributes));
        foreach ($permissions as $permission) {
            Permission::query()->firstOrCreate(
                ['key' => $permission],
                [
                    'description' => $permission,
                    'group' => 'test',
                    'module' => 'Test',
                ],
            );
        }
        $grants = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn ($id): array => [(int) $id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($grants);

        return $user;
    }
}
