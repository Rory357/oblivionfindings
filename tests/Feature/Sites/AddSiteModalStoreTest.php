<?php

namespace Tests\Feature\Sites;

use App\Models\AssetGeofence;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\SiteHouseRoom;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 — SiteController@store fan-out + index reference props.
 */
class AddSiteModalStoreTest extends TestCase
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

    public function test_store_persists_coverage_with_role_requirements_and_day_fanout(): void
    {
        $payload = $this->basePayload([
            'coverage' => [[
                'name' => 'Day cover',
                'coverage_type' => 'day',
                'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                'starts_time' => '07:00',
                'ends_time' => '15:00',
                'minimum_staff' => 2,
                'shift_type' => 'standard',
                'allow_overstaffing' => true,
                'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1],
            ]],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();

        $site = Site::where('name', 'Modal House')->firstOrFail();
        $rows = SiteCoverageRequirement::where('site_id', $site->id)->get();

        // One card × 7 days → 7 rows.
        $this->assertCount(7, $rows);
        $this->assertEqualsCanonicalizing(
            ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
            $rows->pluck('day_of_week')->all(),
        );

        // Role map → [{key,minimum}] with zero counts dropped.
        $first = $rows->first();
        $this->assertEqualsCanonicalizing(
            [['key' => 'caregiver', 'minimum' => 2], ['key' => 'med_competent', 'minimum' => 1]],
            $first->role_requirements,
        );
        $this->assertTrue((bool) $first->allow_overstaffing);
        $this->assertSame('standard', $first->shift_type);
    }

    public function test_twenty_four_seven_payload_creates_three_coverage_types(): void
    {
        $payload = $this->basePayload(['coverage' => $this->twentyFourSevenCoverage()]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();

        $site = Site::where('name', 'Modal House')->firstOrFail();
        $rows = SiteCoverageRequirement::where('site_id', $site->id)->get();

        // 3 cards × 7 days = 21 rows; 3 distinct coverage types.
        $this->assertCount(21, $rows);
        $this->assertEqualsCanonicalizing(
            ['day', 'evening', 'overnight'],
            $rows->pluck('coverage_type')->unique()->values()->all(),
        );
    }

    public function test_store_persists_staff_requirements(): void
    {
        $payload = $this->basePayload([
            'credentials' => [
                ['key' => 'first_aid', 'name' => 'First Aid Certificate', 'category' => 'mandatory', 'expiry_period_months' => 24],
                ['key' => 'manual_handling', 'name' => 'Manual Handling', 'category' => 'recommended', 'expiry_period_months' => 0],
            ],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $first = SiteStaffRequirement::where('site_id', $site->id)->where('requirement_name', 'First Aid Certificate')->firstOrFail();
        $this->assertSame('mandatory', $first->category);
        $this->assertTrue((bool) $first->certification_required);
        $this->assertSame(24, (int) $first->expiry_period_months);

        $second = SiteStaffRequirement::where('site_id', $site->id)->where('requirement_name', 'Manual Handling')->firstOrFail();
        $this->assertFalse((bool) $second->certification_required);
        $this->assertNull($second->expiry_period_months); // 0 stored as null
    }

    public function test_store_dedupes_duplicate_credential(): void
    {
        $payload = $this->basePayload([
            'credentials' => [
                ['key' => 'first_aid', 'name' => 'First Aid Certificate', 'category' => 'recommended', 'expiry_period_months' => 12],
                ['key' => 'first_aid', 'name' => 'First Aid Certificate', 'category' => 'mandatory', 'expiry_period_months' => 24],
            ],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $rows = SiteStaffRequirement::where('site_id', $site->id)->where('requirement_name', 'First Aid Certificate')->get();
        $this->assertCount(1, $rows); // unique(site_id, requirement_name) respected
        $this->assertSame('mandatory', $rows->first()->category); // last write wins
    }

    public function test_store_creates_circle_geofence_when_coords_present(): void
    {
        $payload = $this->basePayload([
            'latitude' => -36.8523,
            'longitude' => 174.7460,
            'geofence' => ['mode' => 'radius', 'radius_m' => 150, 'breach_type' => 'both', 'is_active' => true],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $geofence = AssetGeofence::where('site_id', $site->id)->whereNull('asset_id')->firstOrFail();
        $this->assertSame('circle', $geofence->type);
        $this->assertSame('house', $geofence->scope);
        $this->assertSame('both', $geofence->breach_type);
        $this->assertTrue((bool) $geofence->is_active);
        $this->assertSame(150, (int) $geofence->shape['radius_m']);
        $this->assertEqualsWithDelta(-36.8523, (float) $geofence->shape['center']['lat'], 0.0001);
        $this->assertEqualsWithDelta(174.7460, (float) $geofence->shape['center']['lng'], 0.0001);

        // Feeds readiness via active_geofences_count.
        $this->assertSame(1, $site->geofences()->where('is_active', true)->count());
    }

    public function test_store_skips_geofence_without_coords(): void
    {
        $payload = $this->basePayload([
            'geofence' => ['mode' => 'radius', 'radius_m' => 150, 'breach_type' => 'both', 'is_active' => true],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $this->assertSame(0, AssetGeofence::where('site_id', $site->id)->count());
    }

    public function test_store_converts_food_budget_to_cents_and_persists_finance(): void
    {
        $payload = $this->basePayload([
            'total_capacity' => 6,
            'rent_amount' => 650,
            'rent_frequency' => 'weekly',
            'lease_start_date' => '2026-01-01',
            'lease_end_date' => '2026-12-31',
            'landlord_name' => 'Acme Property',
            'landlord_contact' => '021 222 333',
            'weekly_food_budget' => 240.50,
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $this->assertSame(24050, (int) $site->weekly_food_budget_cents);
        $this->assertSame(6, (int) $site->total_capacity);
        $this->assertSame('weekly', $site->rent_frequency);
        $this->assertSame('Acme Property', $site->landlord_name);
        $this->assertSame('2026-01-01', $site->lease_start_date->toDateString());
    }

    public function test_store_with_no_rostering_creates_no_extra_rows(): void
    {
        $this->actingAs($this->admin)->post('/sites', $this->basePayload())->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $this->assertSame(0, SiteCoverageRequirement::where('site_id', $site->id)->count());
        $this->assertSame(0, SiteStaffRequirement::where('site_id', $site->id)->count());
        $this->assertSame(0, AssetGeofence::where('site_id', $site->id)->count());
    }

    public function test_index_exposes_add_site_reference_data(): void
    {
        // A source site with coverage + a credential, to prove copy-from clone.
        $source = Site::factory()->create(['type' => 'house', 'name' => 'Source House']);
        SiteCoverageRequirement::create([
            'site_id' => $source->id,
            'name' => 'Day cover',
            'coverage_type' => 'day',
            'day_of_week' => 'mon',
            'starts_time' => '07:00',
            'ends_time' => '15:00',
            'minimum_staff' => 2,
            'role_requirements' => [['key' => 'caregiver', 'minimum' => 2]],
            'allow_overstaffing' => true,
            'is_active' => true,
        ]);
        SiteStaffRequirement::create([
            'site_id' => $source->id,
            'requirement_name' => 'First Aid Certificate',
            'category' => 'mandatory',
            'certification_required' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/sites')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('addSite.credentialCatalogue', 6)
                ->has('addSite.coverageRoleKeys', 3)
                ->where('addSite.credentialCatalogue.0.key', 'first_aid')
                ->has('addSite.copyableSites')
                ->has('addSite.regionOptions')
                ->where('addSite.copyableSites', fn ($sites) => collect($sites)->contains(
                    fn ($s) => $s['name'] === 'Source House'
                        && count($s['coverage']) === 1
                        && count($s['credentials']) === 1
                ))
            );
    }

    public function test_store_persists_room_type_via_is_assignable(): void
    {
        $payload = $this->basePayload([
            'rooms' => [
                ['name' => 'Bedroom 1', 'notes' => '', 'is_assignable' => true],
                ['name' => 'Lounge', 'notes' => 'Shared space', 'is_assignable' => false],
            ],
        ]);

        $this->actingAs($this->admin)->post('/sites', $payload)->assertRedirect();
        $site = Site::where('name', 'Modal House')->firstOrFail();

        $bedroom = SiteHouseRoom::where('site_id', $site->id)->where('name', 'Bedroom 1')->firstOrFail();
        $communal = SiteHouseRoom::where('site_id', $site->id)->where('name', 'Lounge')->firstOrFail();
        $this->assertTrue((bool) $bedroom->is_assignable);   // bedroom
        $this->assertFalse((bool) $communal->is_assignable); // communal / shared
    }

    public function test_update_persists_food_budget_cents_and_drops_unhandled_rostering(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", [
                'name' => $site->name,
                'type' => 'house',
                'is_active' => true,
                'weekly_food_budget' => 180.25,
                'total_capacity' => 5,
                // Accepted by UpdateSiteRequest but the edit-via-modal fan-out is a
                // follow-up, so update() drops these rather than silently dropping
                // them via mass-assignment.
                'coverage' => [[
                    'name' => 'Day cover',
                    'coverage_type' => 'day',
                    'days' => ['mon'],
                    'starts_time' => '07:00',
                    'ends_time' => '15:00',
                    'minimum_staff' => 1,
                ]],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $site->refresh();
        $this->assertSame(18025, (int) $site->weekly_food_budget_cents);
        $this->assertSame(5, (int) $site->total_capacity);
        // Rostering fan-out is store-only for now; update must not persist it.
        $this->assertSame(0, SiteCoverageRequirement::where('site_id', $site->id)->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Modal House',
            'type' => 'house',
            'is_active' => true,
        ], $overrides);
    }

    /**
     * The "24/7 staffed" preset rows from the design prototype.
     *
     * @return array<int, array<string, mixed>>
     */
    private function twentyFourSevenCoverage(): array
    {
        $allWeek = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        return [
            ['name' => 'Day cover', 'coverage_type' => 'day', 'days' => $allWeek, 'starts_time' => '07:00', 'ends_time' => '15:00', 'minimum_staff' => 2, 'shift_type' => 'standard', 'allow_overstaffing' => true, 'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1]],
            ['name' => 'Evening cover', 'coverage_type' => 'evening', 'days' => $allWeek, 'starts_time' => '15:00', 'ends_time' => '23:00', 'minimum_staff' => 2, 'shift_type' => 'standard', 'allow_overstaffing' => true, 'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1]],
            ['name' => 'Overnight cover', 'coverage_type' => 'overnight', 'days' => $allWeek, 'starts_time' => '23:00', 'ends_time' => '07:00', 'minimum_staff' => 1, 'shift_type' => 'sleepover', 'allow_overstaffing' => false, 'roles' => ['caregiver' => 1, 'driver' => 0, 'med_competent' => 0]],
        ];
    }
}
