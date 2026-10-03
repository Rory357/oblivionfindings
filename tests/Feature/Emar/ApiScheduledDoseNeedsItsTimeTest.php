<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A scheduled medicine recorded through the mobile API must name the dose it
 * records (scheduled_for). Without it the record can't be matched to the
 * dose — no duplicate check, no slot outcome — so the API answers 422 on that
 * field instead of a bare "not found". As-needed doses carry no dose time.
 */
class ApiScheduledDoseNeedsItsTimeTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-10 09:05:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $site = Site::factory()->create(['is_active' => true]);
        $context = ServiceContext::factory()->create(['name' => 'API dose time', 'type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id, 'status' => 'active']);

        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->first());
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $site->id,
            'service_context_id' => $context->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_scheduled_dose_without_its_time_is_refused_on_that_field(): void
    {
        $order = $this->order(['dose_times' => ['09:00']]);
        $url = "/api/medications/clients/{$this->client->id}/medications/{$order->id}/administrations";

        $this->actingAs($this->worker, 'sanctum')
            ->postJson($url, ['status' => 'given', 'dose_given' => '500mg'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_for');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->actingAs($this->worker, 'sanctum')
            ->postJson($url, [
                'status' => 'given',
                'dose_given' => '500mg',
                'scheduled_for' => Carbon::parse('2026-06-10 09:00', 'Pacific/Auckland')->toIso8601String(),
            ])
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed');
        $this->assertSame(1, ClientMedicationAdministration::query()->count());
    }

    public function test_an_as_needed_dose_needs_no_dose_time(): void
    {
        $order = $this->order(['dose_times' => null, 'is_prn' => true, 'max_per_day' => 4, 'prn_reason' => 'Pain']);

        $this->actingAs($this->worker, 'sanctum')
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$order->id}/administrations", [
                'status' => 'given',
                'dose_given' => '500mg',
                'reason' => 'Pain',
            ])
            ->assertOk()
            ->assertJsonPath('sync.status', 'processed');
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $overrides): ClientMedication
    {
        // Entered well before today's doses (nothing is owed before an order exists).
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::parse('2026-06-09 12:00:00', 'Pacific/Auckland')->utc());
        $order = ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '500mg',
            'frequency' => 'Daily',
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
        Carbon::setTestNow($now);

        return $order;
    }
}
