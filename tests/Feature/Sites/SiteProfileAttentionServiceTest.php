<?php

namespace Tests\Feature\Sites;

use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\EmergencyDrill;
use App\Models\PpeInventory;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteDocument;
use App\Models\SiteHazard;
use App\Models\SiteInspectionSchedule;
use App\Models\User;
use App\Services\ShiftCoverageService;
use App\Services\Sites\SiteProfileAttentionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteProfileAttentionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);
        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $this->site = Site::factory()->create(['type' => 'house']);
    }

    public function test_it_aggregates_permission_shaped_attention_with_real_resolution_paths(): void
    {
        $hazard = SiteHazard::create([
            'site_id' => $this->site->id,
            'reported_by_user_id' => $this->admin->id,
            'hazard_type' => 'slip_trip_fall',
            'severity' => 'critical',
            'likelihood' => 'likely',
            'description' => 'Loose flooring at the main entrance.',
            'status' => 'open',
            'due_date' => now()->subDays(2),
            'review_date' => now()->subDay(),
        ]);

        SiteHazard::create([
            'site_id' => Site::factory()->create()->id,
            'reported_by_user_id' => $this->admin->id,
            'hazard_type' => 'other',
            'severity' => 'critical',
            'likelihood' => 'likely',
            'description' => 'This other Site must not leak.',
            'status' => 'open',
            'due_date' => now()->subDay(),
        ]);

        SiteInspectionSchedule::create([
            'site_id' => $this->site->id,
            'inspection_type' => 'fire_safety',
            'title' => 'Monthly fire inspection',
            'frequency' => 'monthly',
            'first_due_date' => now()->subMonth(),
            'next_due_date' => now()->subDay(),
            'is_active' => true,
        ]);

        EmergencyDrill::factory()->create([
            'site_id' => $this->site->id,
            'title' => 'Fire evacuation drill',
            'status' => 'scheduled',
            'scheduled_at' => now()->subDay(),
        ]);

        SiteDocument::create([
            'site_id' => $this->site->id,
            'uploaded_by_user_id' => $this->admin->id,
            'title' => 'Building warrant',
            'category' => 'compliance',
            'expiry_date' => now()->subDay(),
            'storage_disk' => 'private',
            'storage_path' => 'sites/building-warrant.pdf',
            'original_name' => 'building-warrant.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1200,
        ]);

        Asset::factory()->forSite($this->site)->create([
            'name' => 'Ceiling hoist',
            'requires_maintenance' => true,
            'maintenance_due_at' => now()->subDay(),
        ]);

        PpeInventory::factory()->inspectionDue()->create([
            'site_id' => $this->site->id,
        ]);

        $template = SiteChecklistTemplate::create([
            'key' => 'attention-service-test',
            'name' => 'Attention service test',
            'applicable_to_type' => 'all',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $assignment = SiteChecklistAssignment::create([
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'start_date' => now()->subWeek(),
            'is_active' => true,
        ]);
        SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'scheduled_date' => now()->subDay(),
            'status' => 'scheduled',
        ]);

        $device = Device::factory()->offline()->create([
            'tenant_id' => $this->site->tenant_id,
            'name' => 'Front entrance camera',
            'health_status' => HealthStatus::Critical,
        ]);
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subMonth(),
        ]);

        $coverage = Mockery::mock(ShiftCoverageService::class);
        $coverage->shouldReceive('buildSiteSummaries')
            ->once()
            ->andReturn([[
                'site_id' => $this->site->id,
                'under_covered_windows' => 1,
                'largest_missing_staff' => 2,
                'alerts' => [[
                    'rule_id' => 77,
                    'window_label' => 'Monday 7:00 am–3:00 pm',
                    'missing_staff' => 2,
                    'starts_at' => now()->addDay()->toIso8601String(),
                ]],
            ]]);

        $result = (new SiteProfileAttentionService($coverage))
            ->forSite($this->admin, $this->site);

        $this->assertSame(9, $result['summary']['total']);
        $this->assertSame(3, $result['summary']['critical']);
        $this->assertSame(6, $result['summary']['warning']);
        $this->assertSame([
            'overview' => 0,
            'people' => 1,
            'safety' => 4,
            'operations' => 3,
            'admin' => 1,
        ], $result['groups']);
        $this->assertCount(8, $result['items']);
        $this->assertSame('critical', $result['items'][0]['severity']);
        $this->assertContains('hazard', array_column($result['items'], 'source'));
        $this->assertContains('hardware', array_column($result['items'], 'source'));
        $this->assertContains('coverage', array_column($result['items'], 'source'));

        $hazardItem = collect($result['items'])->firstWhere('source', 'hazard');
        $this->assertSame("/compliance/hazards?hazard={$hazard->id}", $hazardItem['href']);

        foreach ($result['items'] as $item) {
            $this->assertSame([
                'id',
                'source',
                'severity',
                'title',
                'detail',
                'due_date',
                'tab',
                'href',
            ], array_keys($item));
        }

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
        $this->assertStringNotContainsString('credential', $encoded);
    }

    public function test_it_queries_no_protected_sources_for_a_viewer_without_module_permissions(): void
    {
        SiteHazard::create([
            'site_id' => $this->site->id,
            'reported_by_user_id' => $this->admin->id,
            'hazard_type' => 'other',
            'severity' => 'critical',
            'likelihood' => 'likely',
            'description' => 'Protected hazard.',
            'status' => 'open',
            'due_date' => now()->subDay(),
        ]);

        $viewer = User::factory()->create(['approved_at' => now()]);
        $coverage = Mockery::mock(ShiftCoverageService::class);
        $coverage->shouldNotReceive('buildSiteSummaries');

        $result = (new SiteProfileAttentionService($coverage))
            ->forSite($viewer, $this->site);

        $this->assertSame([
            'summary' => ['total' => 0, 'critical' => 0, 'warning' => 0],
            'groups' => [
                'overview' => 0,
                'people' => 0,
                'safety' => 0,
                'operations' => 0,
                'admin' => 0,
            ],
            'items' => [],
        ], $result);
    }
}
