<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientInrRecord;
use App\Models\ClientMedication;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The MAR chart (`/emar/mar`) reuses the same shared meds-board payload that
 * powers `/meds/today`, so the desktop RecordDoseWizard / PrnWizard and the
 * single EnhancedMarService write path are reused verbatim. It also resolves
 * the hero's brand colour from the active (selected client's) site.
 */
class MarChartBoardPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_mar_chart_exposes_shared_board_payload_and_site_brand_colour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.administer.record',
            'medications.controlled.witness',
        ]);
        // "Med-competent" is the competency policy's decision, not the permission.
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id,
            'assessor_id' => User::factory()->create(['approved_at' => now()])->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $site = Site::factory()->create([
            'type' => 'house',
            'is_active' => true,
            'brand_colour' => '#2E7D32',
        ]);

        $client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Three times daily',
            'dose_times' => ['08:00', '10:00', '16:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);

        $this->actingAs($user)
            ->get('/emar/mar?client_id='.$client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->where('site_brand_colour', '#2E7D32')
                ->has('schedule', 3)
                ->where('schedule.0.medication_name', 'Morning tablets')
                ->where('schedule.0.status', 'overdue')
                ->has('witnesses')
                ->has('not_given_reasons')
                ->where('board_user.med_competent', true)
                ->where('selected_client_info.name', 'Aroha Ngata')
            );
    }

    public function test_mar_chart_without_client_has_null_brand_colour(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view']);

        $this->actingAs($user)
            ->get('/emar/mar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->where('site_brand_colour', null)
                ->where('selected_client_info', null)
            );
    }

    public function test_mar_chart_defaults_to_first_resident_when_no_client_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.administer.record',
            'medications.controlled.witness',
        ]);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'brand_colour' => '#2E7D32']);

        $client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'site_id' => $site->id,
            'status' => 'active',
        ]);

        ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['08:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);

        // No ?client_id: the server opens the only resident's chart directly
        // (no two-step picker), so the brand colour + board payload are present.
        $this->actingAs($user)
            ->get('/emar/mar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->where('selected_client_info.name', 'Aroha Ngata')
                ->where('site_brand_colour', '#2E7D32')
                ->has('schedule', 1)
            );
    }

    public function test_mar_chart_defaults_to_last_viewed_resident(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.administer.record',
            'medications.controlled.witness',
        ]);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);

        // Alphabetically-first by last_name (the otherwise-default pick).
        $first = Client::factory()->create(['first_name' => 'Alpha', 'last_name' => 'Aaa', 'site_id' => $site->id, 'status' => 'active']);
        // Last-viewed should win even though it sorts last.
        $recent = Client::factory()->create(['first_name' => 'Zeta', 'last_name' => 'Zzz', 'site_id' => $site->id, 'status' => 'active']);

        foreach ([$first, $recent] as $client) {
            ClientMedication::query()->create([
                'client_id' => $client->id,
                'name' => 'Tablets',
                'dosage' => '1 tablet',
                'frequency' => 'Daily',
                'dose_times' => ['08:00'],
                'is_prn' => false,
                'active' => true,
                'state' => 'active',
            ]);
        }

        // Open Zeta's chart explicitly (records last-viewed in the session)…
        $this->actingAs($user)->get('/emar/mar?client_id='.$recent->id)->assertOk();

        // …then a bare /emar/mar reopens Zeta, not the alphabetical-first Alpha.
        $this->actingAs($user)
            ->get('/emar/mar')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->where('selected_client_info.name', 'Zeta Zzz')
            );
    }

    public function test_retired_inr_index_route_redirects_to_mar_chart(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view']);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);

        // The standalone INR endpoint is retired — INR lives on the MAR clinical
        // rail now — so a direct hit redirects to the resident's chart (never a
        // raw JSON dump or 404).
        $this->actingAs($user)
            ->get('/emar/clients/'.$client->id.'/inr')
            ->assertRedirect('/emar/mar?client_id='.$client->id);
    }

    public function test_mar_inr_list_shows_unlinked_readings_but_conceals_controlled_and_cross_client_links(): void
    {
        $this->seed(RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, ['medications.view']);
        $user->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'medications.controlled.view')->value('id') => ['allowed' => false],
        ]);

        $site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $neighbour = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $warfarin = $this->prnMedication($client, 'Warfarin');
        $morphine = $this->prnMedication($client, 'Morphine sulfate', true);
        $neighbourWarfarin = $this->prnMedication($neighbour, 'Warfarin');

        foreach ([
            [null, 4.8],
            [$warfarin->id, 2.5],
            // Controlled — concealed from a reader without controlled view.
            [$morphine->id, 5.5],
            // Another resident's order — never a canonical link.
            [$neighbourWarfarin->id, 6.1],
        ] as [$medicationId, $value]) {
            ClientInrRecord::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medicationId,
                'inr_value' => $value,
                'target_range_low' => 2.0,
                'target_range_high' => 3.0,
                'tested_on' => today()->toDateString(),
                'recorded_by' => $user->id,
            ]);
        }

        // The MAR rail labels the reading whose client_medication_id is null.
        $this->actingAs($user)
            ->get('/emar/mar?client_id='.$client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->has('marData.inr_records', 2)
                ->where('marData.inr_records', fn ($records) => collect($records)
                    ->mapWithKeys(fn (array $r) => [(string) $r['inr_value'] => $r['client_medication_id']])
                    ->sortKeys()
                    ->all() === ['2.5' => $warfarin->id, '4.8' => null])
            );
    }

    private function prnMedication(Client $client, string $name, bool $controlled = false): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'As required',
            'is_prn' => true,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
        ]);
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionMap = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
