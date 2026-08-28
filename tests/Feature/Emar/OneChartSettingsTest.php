<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\MedicationAdminRule;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OneChartSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'approved_at' => now(),
            'password' => Hash::make('admin-secret'),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->first());

        $adminSite = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'primary_site_id' => $adminSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        $serviceContext = ServiceContext::factory()->create([
            'name' => '1CHART Settings Test',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'service_context_id' => $serviceContext->id,
            'site_id' => $adminSite->id,
        ]);
    }

    public function test_manager_can_create_update_and_delete_admin_rules(): void
    {
        $this->actingAs($this->admin)->get('/emar/settings')->assertOk();

        $this->actingAs($this->admin)
            ->post('/emar/settings/rules', [
                'match_type' => 'medicine_name',
                'match_value' => 'Warfarin',
                'requires_countersign' => true,
                'required_observations' => ['pulse'],
                'active' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('medication_admin_rules', [
            'match_type' => 'medicine_name',
            'match_value' => 'Warfarin',
            'requires_countersign' => true,
            'created_by' => $this->admin->id,
        ]);

        $rule = MedicationAdminRule::query()->firstOrFail();
        $this->assertSame(['pulse'], $rule->required_observations);

        $this->actingAs($this->admin)
            ->put("/emar/settings/rules/{$rule->id}", [
                'match_type' => 'route',
                'match_value' => 'Intravenous',
                'requires_countersign' => true,
                'required_observations' => [],
                'active' => false,
            ])
            ->assertRedirect();

        $rule->refresh();
        $this->assertSame('route', $rule->match_type);
        $this->assertSame('Intravenous', $rule->match_value);
        $this->assertFalse($rule->active);

        $this->actingAs($this->admin)
            ->delete("/emar/settings/rules/{$rule->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('medication_admin_rules', ['id' => $rule->id]);
    }

    public function test_admin_rule_requires_a_countersign_or_observation(): void
    {
        $this->actingAs($this->admin)
            ->from('/emar/settings')
            ->post('/emar/settings/rules', [
                'match_type' => 'medicine_name',
                'match_value' => 'Paracetamol',
                'requires_countersign' => false,
                'required_observations' => ['not_a_real_observation'],
            ])
            ->assertSessionHasErrors('required_observations.0');

        $this->assertDatabaseCount('medication_admin_rules', 0);
    }

    public function test_support_worker_cannot_manage_admin_rules(): void
    {
        $worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $worker->roles()->attach(Role::query()->where('name', 'support_worker')->first());

        $this->actingAs($worker)
            ->post('/emar/settings/rules', [
                'match_type' => 'medicine_name',
                'match_value' => 'Warfarin',
                'requires_countersign' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('medication_admin_rules', 0);
    }

    public function test_medication_settings_persist_care_level_and_review_cadence(): void
    {
        $this->actingAs($this->admin)
            ->post("/emar/clients/{$this->client->id}/medication-settings", [
                'care_level' => 'dementia',
                'chart_review_interval_months' => 1,
                'next_chart_review_date' => today()->addMonth()->toDateString(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clients', [
            'id' => $this->client->id,
            'care_level' => 'dementia',
            'chart_review_interval_months' => 1,
            'next_chart_review_date' => today()->addMonth()->toDateString(),
        ]);
    }
}
