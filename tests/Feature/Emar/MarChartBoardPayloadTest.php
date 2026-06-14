<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
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
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(\Database\Seeders\RbacSeeder::class);

        $user = $this->makeRoleUser('admin');
        $this->grantPermissions($user, [
            'medications.view',
            'medications.administer.record',
            'medications.controlled.witness',
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
        $this->seed(\Database\Seeders\RbacSeeder::class);

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
