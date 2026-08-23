<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Enums\ProtocolFrequency;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClinicalProtocolManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ClinicalPermissionsSeeder::class);
        $this->site = Site::factory()->create();
    }

    public function test_protocol_register_renders_with_adherence_context(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'instructions' => 'Record before breakfast.',
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addHours(3),
            'status' => 'pending',
        ]);
        ClinicalProtocolSchedule::factory()->overdue()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);
        ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
            'completed_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/protocols')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/Protocols')
                ->has('protocols.data', 1)
                ->where('stats.active_protocols', 1)
                ->where('stats.schedules_overdue', 1)
                ->where('protocols.data.0.schedule_counts.pending', 2)
                ->where('protocols.data.0.schedule_counts.overdue', 1)
                ->where('protocols.data.0.schedule_counts.completed_30d', 1)
                ->where('can_manage', true)
            );
    }

    public function test_protocol_register_filters_for_view_only_user(): void
    {
        $user = $this->createUserWithRole('team_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);

        ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $client->id,
            'is_active' => true,
        ]);
        ClinicalProtocol::factory()->inactive()->create([
            'client_id' => $otherClient->id,
            'frequency' => ProtocolFrequency::Weekly,
        ]);

        $this->actingAs($user)
            ->get('/health-clinical/protocols?client_id='.$client->id.'&frequency=daily&status=active')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('protocols.data', 1)
                ->where('protocols.data.0.client_id', $client->id)
                ->where('protocols.data.0.frequency', 'daily')
                ->where('protocols.data.0.is_active', true)
                ->where('can_manage', false)
            );
    }

    public function test_support_worker_cannot_access_protocol_register(): void
    {
        $user = $this->createUserWithRole('support_worker');

        $this->actingAs($user)
            ->get('/health-clinical/protocols')
            ->assertForbidden();
    }

    public function test_manage_user_can_view_protocol_create_page(): void
    {
        $user = $this->createUserWithRole('coordinator');

        $this->actingAs($user)
            ->get('/health-clinical/protocols/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/protocols/Create')
                ->has('form_options.clients')
                ->has('form_options.observation_types')
                ->has('form_options.frequencies')
            );
    }

    public function test_view_only_user_cannot_access_protocol_create_page(): void
    {
        $user = $this->createUserWithRole('team_lead');

        $this->actingAs($user)
            ->get('/health-clinical/protocols/create')
            ->assertForbidden();
    }

    public function test_can_create_protocol(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);

        $this->actingAs($user)
            ->post('/health-clinical/protocols', [
                'idempotency_key' => (string) Str::uuid(),
                'client_id' => $client->id,
                'name' => 'Twice daily pain monitoring',
                'observation_type' => ObservationType::Pain->value,
                'frequency' => ProtocolFrequency::TwiceDaily->value,
                'instructions' => 'Record before medication rounds.',
                'alert_if_missed_hours' => 12,
                'is_active' => true,
                'starts_at' => '2026-04-14',
                'ends_at' => '2026-05-14',
            ])
            ->assertRedirect('/health-clinical/protocols');

        $this->assertDatabaseHas('clinical_protocols', [
            'client_id' => $client->id,
            'created_by' => $user->id,
            'name' => 'Twice daily pain monitoring',
            'observation_type' => ObservationType::Pain->value,
            'frequency' => ProtocolFrequency::TwiceDaily->value,
            'alert_if_missed_hours' => 12,
            'is_active' => true,
        ]);
    }

    public function test_edit_page_exposes_when_structure_can_still_be_changed(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get("/health-clinical/protocols/{$protocol->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('health-clinical/protocols/Edit')
                ->where('protocol.id', $protocol->id)
                ->where('can_edit_structure', true)
            );
    }

    public function test_update_rejects_structural_changes_when_schedule_history_exists(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $this->actingAs($user)
            ->from("/health-clinical/protocols/{$protocol->id}/edit")
            ->put("/health-clinical/protocols/{$protocol->id}", [
                'idempotency_key' => (string) Str::uuid(),
                'name' => 'Updated protocol',
                'observation_type' => ObservationType::Vitals->value,
                'frequency' => ProtocolFrequency::Weekly->value,
                'instructions' => 'Updated instructions.',
                'alert_if_missed_hours' => 48,
                'is_active' => true,
            ])
            ->assertRedirect("/health-clinical/protocols/{$protocol->id}/edit")
            ->assertSessionHasErrors(['observation_type', 'frequency']);

        $protocol->refresh();

        $this->assertEquals(ObservationType::Weight, $protocol->observation_type);
        $this->assertEquals(ProtocolFrequency::Daily, $protocol->frequency);
    }

    public function test_can_update_non_structural_protocol_fields(): void
    {
        $user = $this->createUserWithRole('clinical_lead');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'instructions' => 'Record before breakfast.',
            'alert_if_missed_hours' => 24,
            'is_active' => true,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $this->actingAs($user)
            ->put("/health-clinical/protocols/{$protocol->id}", [
                'idempotency_key' => (string) Str::uuid(),
                'name' => 'Daily weight management',
                'instructions' => 'Record before breakfast and before fluid rounds.',
                'alert_if_missed_hours' => 36,
                'is_active' => false,
                'starts_at' => '2026-04-14',
                'ends_at' => '2026-06-14',
            ])
            ->assertRedirect('/health-clinical/protocols');

        $this->assertDatabaseHas('clinical_protocols', [
            'id' => $protocol->id,
            'name' => 'Daily weight management',
            'alert_if_missed_hours' => 36,
            'is_active' => false,
        ]);
    }

    public function test_can_toggle_protocol_active_state(): void
    {
        $user = $this->createUserWithRole('coordinator');
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $client->id,
            'created_by' => $user->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch("/health-clinical/protocols/{$protocol->id}/toggle-active", [
                'idempotency_key' => (string) Str::uuid(),
                'is_active' => false,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('clinical_protocols', [
            'id' => $protocol->id,
            'is_active' => false,
        ]);
    }

    protected function createUserWithRole(string $roleName): User
    {
        $user = User::factory()->create([
            'role' => $roleName,
            'approved_at' => now(),
        ]);
        $role = Role::where('name', $roleName)->first();
        if ($role) {
            $user->roles()->attach($role);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }
}
