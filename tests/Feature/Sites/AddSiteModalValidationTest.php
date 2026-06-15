<?php

namespace Tests\Feature\Sites;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1 — validation foundation for the Add Site modal payload.
 *
 * Exercises the new StoreSiteRequest / UpdateSiteRequest rule blocks
 * (coverage, credentials, shift templates, geofence, finance, capacity).
 * Persistence of these arrays is wired in S2; here we only assert the
 * validator accepts a well-formed payload and rejects malformed ones.
 */
class AddSiteModalValidationTest extends TestCase
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

    /** A complete, well-formed modal payload passes validation and creates the site. */
    public function test_accepts_full_rostering_and_finance_payload(): void
    {
        $this->actingAs($this->admin)
            ->post('/sites', $this->fullPayload())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('sites', ['name' => 'Modal House']);
    }

    public function test_rejects_invalid_coverage_type(): void
    {
        $payload = $this->fullPayload();
        $payload['coverage'][0]['coverage_type'] = 'graveyard';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('coverage.0.coverage_type');
    }

    public function test_rejects_invalid_coverage_day(): void
    {
        $payload = $this->fullPayload();
        $payload['coverage'][0]['days'] = ['mon', 'funday'];

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('coverage.0.days.1');
    }

    public function test_rejects_bad_coverage_time_format(): void
    {
        $payload = $this->fullPayload();
        $payload['coverage'][0]['starts_time'] = '7am';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('coverage.0.starts_time');
    }

    public function test_rejects_minimum_staff_out_of_range(): void
    {
        $payload = $this->fullPayload();
        $payload['coverage'][0]['minimum_staff'] = 99;

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('coverage.0.minimum_staff');
    }

    public function test_rejects_role_mix_out_of_range(): void
    {
        $payload = $this->fullPayload();
        $payload['coverage'][0]['roles']['caregiver'] = 50;

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('coverage.0.roles.caregiver');
    }

    public function test_rejects_invalid_credential_category(): void
    {
        $payload = $this->fullPayload();
        $payload['credentials'][0]['category'] = 'optional';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('credentials.0.category');
    }

    public function test_rejects_invalid_geofence_breach_type(): void
    {
        $payload = $this->fullPayload();
        $payload['geofence']['breach_type'] = 'sideways';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('geofence.breach_type');
    }

    public function test_rejects_geofence_radius_out_of_range(): void
    {
        $payload = $this->fullPayload();
        $payload['geofence']['radius_m'] = 5000;

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('geofence.radius_m');
    }

    public function test_rejects_lease_end_before_lease_start(): void
    {
        $payload = $this->fullPayload();
        $payload['lease_start_date'] = '2026-06-01';
        $payload['lease_end_date'] = '2026-05-01';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('lease_end_date');
    }

    public function test_rejects_invalid_rent_frequency(): void
    {
        $payload = $this->fullPayload();
        $payload['rent_frequency'] = 'hourly';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('rent_frequency');
    }

    public function test_rejects_bad_shift_template_time(): void
    {
        $payload = $this->fullPayload();
        $payload['shift_templates'][0]['starts_time'] = 'noon';

        $this->actingAs($this->admin)
            ->post('/sites', $payload)
            ->assertSessionHasErrors('shift_templates.0.starts_time');
    }

    /** The edit path shares the same rule additions via UpdateSiteRequest. */
    public function test_update_request_accepts_rostering_payload(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

        $payload = $this->fullPayload();
        $payload['name'] = $site->name;

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    public function test_update_request_rejects_invalid_coverage(): void
    {
        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

        $payload = $this->fullPayload();
        $payload['name'] = $site->name;
        $payload['coverage'][0]['coverage_type'] = 'graveyard';

        $this->actingAs($this->admin)
            ->put("/sites/{$site->id}", $payload)
            ->assertSessionHasErrors('coverage.0.coverage_type');
    }

    /**
     * A representative happy-path payload mirroring the modal contract
     * (README state shape + backend plan §2). service_context_id is omitted
     * so the test needs no ServiceContext fixture.
     *
     * @return array<string, mixed>
     */
    private function fullPayload(): array
    {
        return [
            'name' => 'Modal House',
            'type' => 'house',
            'phone' => '09 555 1234',
            'email' => 'modal@example.com',
            'is_active' => true,
            'total_capacity' => 6,

            'address_line_1' => '12 Ponsonby Road',
            'address_line_2' => 'Unit 3',
            'suburb' => 'Ponsonby',
            'city' => 'Auckland',
            'postcode' => '1011',
            'country' => 'New Zealand',
            'region' => 'Auckland',
            'latitude' => -36.8523,
            'longitude' => 174.7460,
            'access_instructions' => 'Lockbox by the front door.',

            'coverage' => [
                [
                    'name' => 'Day cover',
                    'coverage_type' => 'day',
                    'days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
                    'starts_time' => '07:00',
                    'ends_time' => '15:00',
                    'minimum_staff' => 2,
                    'shift_type' => 'standard',
                    'allow_overstaffing' => true,
                    'roles' => ['caregiver' => 2, 'driver' => 0, 'med_competent' => 1],
                ],
            ],

            'credentials' => [
                [
                    'key' => 'first_aid',
                    'name' => 'First Aid Certificate',
                    'category' => 'mandatory',
                    'expiry_period_months' => 24,
                ],
            ],

            'shift_templates' => [
                ['name' => 'Morning', 'starts_time' => '07:00', 'ends_time' => '15:00'],
            ],

            'geofence' => [
                'mode' => 'radius',
                'radius_m' => 120,
                'breach_type' => 'both',
                'is_active' => true,
            ],

            'rent_amount' => 650,
            'rent_frequency' => 'weekly',
            'lease_start_date' => '2026-01-01',
            'lease_end_date' => '2026-12-31',
            'landlord_name' => 'Acme Property',
            'landlord_contact' => '021 222 333',
            'weekly_food_budget' => 240.50,
        ];
    }
}
