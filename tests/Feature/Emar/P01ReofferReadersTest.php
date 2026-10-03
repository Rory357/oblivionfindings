<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\Medication\DoseSlots\ClientCalendarDoses;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P01 C2 live walk follow-ups: a re-offer after a refusal is a second
 * record in the same slot, so every reader that shows "the" record of a
 * dose shows the latest effective one (the re-offer), as the dose slot does
 * — the MAR (and the shift card, handover and API built on it), the MAR
 * chart, a guided round and the person's calendar. Also: a severe register
 * allergy is named as an allergy block, and an as-needed dose keeps the
 * order's dose words.
 */
class P01ReofferReadersTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private Site $site;

    private ServiceContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->context = ServiceContext::factory()->create(['name' => 'P01 readers', 'type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'preferred_name' => 'Aroha',
            'service_context_id' => $this->context->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
        $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'name' => 'Priya Shah']);
        $this->worker->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.administer.record'])->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => User::factory()->create(['role' => 'manager', 'approved_at' => now()])->id,
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
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
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

    public function test_the_mar_shows_the_reoffer_not_the_refusal_it_followed(): void
    {
        [$order, $refusal, $reoffer] = $this->refusedThenGiven();

        $this->actingAs($this->worker);
        $mar = app(EnhancedMarService::class)->build($this->client->fresh(), now(), now(), null, true);
        $row = collect($mar['scheduled'])->firstWhere('client_medication_id', $order->id)
            ?? collect($mar['scheduled'])->first(fn (array $r): bool => ($r['medication']['id'] ?? null) === $order->id);
        $this->assertSame('completed', $row['schedule_state']);
        $this->assertSame($reoffer->id, $row['administration']['id']);
        $this->assertSame('given', $row['administration']['status']);
    }

    public function test_the_mar_chart_shows_the_reoffer_and_keeps_the_refusal_as_history(): void
    {
        [$order, $refusal, $reoffer] = $this->refusedThenGiven();

        $this->actingAs($this->worker)
            ->get('/emar/mar?client_id='.$this->client->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/MarCharts')
                ->where('marData.scheduled.0.id', $order->id)
                ->where('marData.scheduled.0.administrations.0.id', $reoffer->id)
                ->where('marData.scheduled.0.administrations.0.status', 'given')
                ->where('marData.scheduled.0.administrations.1.id', $refusal->id)
                ->where('marData.scheduled.0.administrations.1.status', 'refused'));
    }

    public function test_a_guided_round_shows_the_reoffer(): void
    {
        [, , $reoffer] = $this->refusedThenGiven();
        $round = MedicationRound::query()->create([
            'site_id' => $this->site->id,
            'name' => 'Morning round',
            'round_type' => 'scheduled',
            'scheduled_time' => '09:30',
            'window_minutes' => 60,
            'round_date' => '2026-04-30',
            'status' => 'in_progress',
            'started_by' => $this->worker->id,
        ]);

        $item = app(GuidedRoundService::class)->items($round->fresh(), true)[0];
        $this->assertSame('given', $item['dose_state']);
        $this->assertSame($reoffer->id, $item['administration']['id']);
    }

    public function test_the_persons_calendar_shows_one_dose_with_the_reoffer(): void
    {
        [$order] = $this->refusedThenGiven();

        $events = collect(app(ClientCalendarDoses::class)->events(
            $this->client->fresh(),
            Carbon::parse('2026-04-30 00:00', 'Pacific/Auckland'),
            Carbon::parse('2026-05-01 00:00', 'Pacific/Auckland'),
            true,
        ))->filter(fn (array $e): bool => ($e['extendedProps']['medication_name'] ?? null) === $order->name);

        $this->assertCount(1, $events);
        $this->assertSame('given', $events->first()['extendedProps']['status']);
    }

    public function test_a_severe_register_allergy_is_named_as_an_allergy_block(): void
    {
        $order = $this->order(['09:30'], ['name' => 'Amoxicillin 500mg']);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Amoxicillin',
            'recorded_by' => $this->worker->id,
            'severity' => 'severe',
        ]);

        $this->actingAs($this->worker)
            ->getJson('/meds/today/doses/requirements?'.http_build_query([
                'client_medication_id' => $order->id,
                'scheduled_for' => Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->toIso8601String(),
            ]))
            ->assertOk()
            ->assertJsonPath('block_given.key', DoseRecordingRequirements::BLOCK_ALLERGY)
            ->assertJsonPath('block_given.facts.allergen', 'Amoxicillin')
            ->assertJsonPath('block_given.facts.severity', 'severe');
    }

    public function test_an_as_needed_dose_keeps_the_orders_dose_words(): void
    {
        $order = $this->order([], [
            'name' => 'Paracetamol 500mg (as needed)',
            'dosage' => '2 tablets (1 g)',
            'is_prn' => true,
            'max_per_day' => 4,
            'prn_reason' => 'Pain',
        ]);

        $this->actingAs($this->worker)
            ->postJson('/meds/today/prn', [
                'client_medication_id' => $order->id,
                'reason' => 'Pain',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertOk();

        $this->assertSame('2 tablets (1 g)', ClientMedicationAdministration::query()->sole()->dose_given);
    }

    // ─── helpers ─────────────────────────────────────────────

    /** @return array{0: ClientMedication, 1: ClientMedicationAdministration, 2: ClientMedicationAdministration} */
    private function refusedThenGiven(): array
    {
        $order = $this->order(['09:30'], ['name' => 'Sertraline 50mg']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->toIso8601String();
        $this->actingAs($this->worker)->postJson('/meds/today/record', [
            'client_medication_id' => $order->id,
            'scheduled_for' => $scheduledFor,
            'status' => 'refused',
            'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T11:00',
        ])->assertOk();
        $refusal = ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->sole();

        Carbon::setTestNow(Carbon::parse('2026-04-30 10:15:00', 'Pacific/Auckland')->utc());
        $this->actingAs($this->worker)->postJson('/meds/today/record', [
            'client_medication_id' => $order->id,
            'scheduled_for' => $scheduledFor,
            'status' => 'given',
            'reoffer_of_id' => $refusal->id,
            'administered_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('sync.status', 'processed');
        $reoffer = ClientMedicationAdministration::query()->where('client_medication_id', $order->id)->latest('id')->first();
        $this->assertSame($refusal->id, (int) $reoffer->reoffer_of_id);

        return [$order, $refusal, $reoffer];
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $doseTimes, array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $order = ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
        Carbon::setTestNow($now);

        return $order;
    }
}
