<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * eMAR audit Lane A5 (EA-005): a chart alert set to "Prompt staff" appears
 * where the dose is recorded, and the worker's reading of it is recorded
 * once per NZ day.
 */
class LaneAChartAlertPromptTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $worker;

    private ClientMedication $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 09:30');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->worker = $this->staff();
        $now = Carbon::getTestNow();
        $this->clock('2026-04-30 00:00');
        $this->order = ClientMedication::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic warfarin 3mg', 'dosage' => '1 tablet', 'dose_amount' => 1,
            'dose_unit' => 'tablet', 'frequency' => 'Daily', 'dose_times' => ['09:30'], 'is_prn' => false, 'controlled_drug' => false,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_prompt_staff_alerts_reach_the_recording_dialog_and_are_read_once_a_day(): void
    {
        $prompt = $this->alert(['type' => 'warfarin', 'title' => 'Hold if INR > 3.5', 'detail' => 'Check today’s INR before giving.', 'prompt_on_open' => true]);
        $this->alert(['type' => 'chart_warning', 'title' => 'Quiet note', 'prompt_on_open' => false]);
        $this->alert(['type' => 'chart_warning', 'title' => 'Switched off', 'prompt_on_open' => true, 'enabled' => false]);
        $this->alert(['type' => 'chart_warning', 'title' => 'Resolved', 'prompt_on_open' => true, 'resolved_at' => now()]);

        $alerts = $this->requirements()->json('chart_alerts');
        $this->assertSame([[
            'id' => $prompt->id, 'type' => 'warfarin', 'title' => 'Hold if INR > 3.5',
            'detail' => 'Check today’s INR before giving.', 'read_at' => null,
        ]], $alerts);

        $this->actingAs($this->worker)->postJson('/meds/today/chart-alerts/'.$prompt->id.'/read')
            ->assertOk()->assertJsonPath('read', true);
        $this->postJson('/meds/today/chart-alerts/'.$prompt->id.'/read')->assertOk();
        $this->assertDatabaseCount('medication_chart_alert_reads', 1);
        $this->assertNotNull($this->requirements()->json('chart_alerts.0.read_at'));

        // A new NZ day asks again.
        $this->clock('2026-05-01 08:00');
        $this->assertNull($this->requirements()->json('chart_alerts.0.read_at'));
    }

    /** Medication settings: confirm every dose instead of once a day. */
    public function test_the_organisation_can_ask_for_a_confirmation_every_dose(): void
    {
        $prompt = $this->alert(['type' => 'warfarin', 'title' => 'Hold if INR > 3.5', 'prompt_on_open' => true]);
        $this->actingAs($this->worker)->postJson('/meds/today/chart-alerts/'.$prompt->id.'/read')->assertOk();
        $this->assertNotNull($this->requirements()->json('chart_alerts.0.read_at'));

        \App\Models\AppSetting::query()->updateOrCreate(
            ['key' => \App\Services\Medication\Recording\ChartAlertPromptSettings::RECONFIRM],
            ['value' => \App\Services\Medication\Recording\ChartAlertPromptSettings::EVERY_DOSE],
        );
        $this->assertNull($this->requirements()->json('chart_alerts.0.read_at'));

        $definition = app(\App\Services\Medication\Settings\MedicationSettingsRegistry::class)->definition('chartAlerts', 'reconfirm');
        $this->assertSame('day', $definition->default);
        $this->assertTrue($definition->loosens('dose', 'day'));
        $this->assertFalse($definition->loosens('day', 'dose'));
    }

    public function test_an_alert_for_someone_outside_the_workers_access_is_not_found(): void
    {
        $elsewhere = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id, 'status' => 'active']);
        $alert = ClientMedicationAlert::create(['client_id' => $elsewhere->id, 'type' => 'chart_warning', 'title' => 'Elsewhere',
            'prompt_on_open' => true, 'enabled' => true]);

        $this->actingAs($this->worker)->postJson('/meds/today/chart-alerts/'.$alert->id.'/read')->assertNotFound();
        $this->assertDatabaseCount('medication_chart_alert_reads', 0);
    }

    private function alert(array $attributes): ClientMedicationAlert
    {
        return ClientMedicationAlert::create(['client_id' => $this->client->id, 'enabled' => true, ...$attributes]);
    }

    private function requirements()
    {
        return $this->actingAs($this->worker->fresh())->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $this->order->id,
            'scheduled_for' => CarbonImmutable::parse('2026-04-30 09:30', 'Pacific/Auckland')->toIso8601String(),
        ]))->assertOk();
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $permissions = Permission::whereIn('key', ['medications.view', 'medications.administer.record'])->get();
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addDay()->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
