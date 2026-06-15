<?php

namespace Tests\Feature\Sites;

use App\Models\AssetGeofence;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\Sites\SiteReadinessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S7 — end-to-end: a 24/7 house created from the Add Site modal payload should
 * persist its coverage + geofence and come out "ready" in SiteReadinessService.
 */
class AddSiteModalE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    public function test_modal_creates_a_ready_247_house(): void
    {
        $allWeek = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        $payload = [
            '_modal' => true,
            'name' => 'Aroha House',
            'type' => 'house',
            'is_active' => true,
            // Satisfy the readiness critical items:
            'phone' => '09 555 1234',
            'email' => 'aroha@example.co.nz',
            'primary_contact_user_id' => $this->admin->id, // site lead
            'emergency_plan_location' => 'Assembly point: front lawn',
            'medication_storage_location' => 'Locked cabinet, office',
            'contacts' => [
                ['type' => 'emergency', 'name' => 'On-call line', 'phone' => '0800 111 222'],
            ],
            // Location + geofence
            'latitude' => -36.8523,
            'longitude' => 174.7460,
            'geofence' => ['mode' => 'radius', 'radius_m' => 150, 'breach_type' => 'both', 'is_active' => true],
            // 24/7 coverage (3 cards x 7 days)
            'coverage' => [
                ['name' => 'Day cover', 'coverage_type' => 'day', 'days' => $allWeek, 'starts_time' => '07:00', 'ends_time' => '15:00', 'minimum_staff' => 2, 'shift_type' => 'standard', 'allow_overstaffing' => true, 'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1]],
                ['name' => 'Evening cover', 'coverage_type' => 'evening', 'days' => $allWeek, 'starts_time' => '15:00', 'ends_time' => '23:00', 'minimum_staff' => 2, 'shift_type' => 'standard', 'allow_overstaffing' => true, 'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1]],
                ['name' => 'Overnight cover', 'coverage_type' => 'overnight', 'days' => $allWeek, 'starts_time' => '23:00', 'ends_time' => '07:00', 'minimum_staff' => 1, 'shift_type' => 'sleepover', 'allow_overstaffing' => false, 'roles' => ['caregiver' => 1, 'driver' => 0, 'med_competent' => 0]],
            ],
            'credentials' => [
                ['key' => 'first_aid', 'name' => 'First Aid Certificate', 'category' => 'mandatory', 'expiry_period_months' => 24],
            ],
        ];

        $this->actingAs($this->admin)
            ->from('/sites')
            ->post('/sites', $payload)
            ->assertRedirect('/sites'); // _modal stays on the index

        $site = Site::where('name', 'Aroha House')->firstOrFail();

        // 3 coverage cards x 7 days = 21 rows, across the three coverage types.
        $rows = SiteCoverageRequirement::where('site_id', $site->id)->get();
        $this->assertCount(21, $rows);
        $this->assertEqualsCanonicalizing(
            ['day', 'evening', 'overnight'],
            $rows->pluck('coverage_type')->unique()->values()->all(),
        );

        // An active circle geofence was seeded.
        $this->assertSame(
            1,
            AssetGeofence::where('site_id', $site->id)->where('is_active', true)->count(),
        );

        // The site is "ready": every critical readiness item is satisfied.
        $readiness = app(SiteReadinessService::class)->evaluate($site->fresh());
        $this->assertSame(
            $readiness['critical_total'],
            $readiness['critical_done'],
            'Expected all critical readiness items done; missing: '.implode(', ', $readiness['missing_critical']),
        );
        $this->assertFalse($readiness['is_active_but_incomplete']);
    }
}
