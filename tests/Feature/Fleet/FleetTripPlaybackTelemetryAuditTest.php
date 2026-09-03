<?php

namespace Tests\Feature\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetTrip;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetTripPlaybackTelemetryAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_playback_endpoint_records_audit_log(): void
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'employee_number' => 'EMP-' . $user->id,
            'work_email' => $user->email,
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
        $permission = Permission::query()->firstOrCreate(['key' => 'fleet.viewAny'], ['description' => 'View all fleet', 'group' => 'test', 'module' => 'Test']);
        $user->permissionOverrides()->attach($permission, ['allowed' => true]);

        $asset = Asset::create([
            'name' => 'Support Van 01',
            'asset_tag' => 'AST-001',
            'category' => 'vehicle',
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        $trip = FleetTrip::create([
            'asset_id' => $asset->id,
            'status' => 'closed',
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)
            ->get("/fleet-assets/trips/{$trip->id}/playback/data");

        $response->assertOk();
        $response->assertJsonStructure(['trip_id', 'truncated', 'points']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fleet.trip.playback.data',
            'auditable_type' => FleetTrip::class,
            'auditable_id' => $trip->id,
            'user_id' => $user->id,
        ]);
    }
}
