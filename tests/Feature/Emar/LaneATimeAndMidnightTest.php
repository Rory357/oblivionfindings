<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
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
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\RecordsDosesLikeTheDialog;

/**
 * eMAR audit Lane A7 (EA-042, EA-043, EA-119, EA-147): NZ time at the edges.
 *  - the repeated hour on the April DST night is recorded at the instant the
 *    worker meant, never guessed;
 *  - an evening dose still due or late stays on Meds today after midnight;
 *  - a refusal can be re-offered while its follow-up is open, past midnight;
 *  - block messages say NZ times.
 */
class LaneATimeAndMidnightTest extends TestCase
{
    use RefreshDatabase;
    use RecordsDosesLikeTheDialog;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 21:00');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active',
            'first_name' => 'Hemi', 'preferred_name' => 'Hemi']);
        $this->worker = $this->staff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** EA-042: 4 April 2027, 02:00–02:59 happens twice. */
    public function test_a_dose_in_the_repeated_dst_hour_needs_its_offset_and_keeps_the_instant(): void
    {
        $this->clock('2027-04-03 20:00');
        $this->onShift('2027-04-03 20:00', '2027-04-04 08:00');
        $prn = $this->order(['name' => 'Synthetic paracetamol', 'is_prn' => true, 'frequency' => 'As needed', 'dose_times' => [], 'prn_reason' => 'Pain'], '2027-04-03 00:00');

        // 02:52 NZST on 4 April, the second time round (14:52 UTC on the 3rd).
        $at = CarbonImmutable::parse('2027-04-03 14:52', 'UTC');
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        $this->prn($prn, ['administered_at' => '2027-04-04T02:50:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('administered_at');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->prn($prn, ['administered_at' => '2027-04-04T02:50:00+12:00'])->assertOk();
        // 02:50 NZST is 14:50 UTC the day before.
        $this->assertSame('2027-04-03 14:50:00', ClientMedicationAdministration::sole()->getRawOriginal('administered_at'));
    }

    /** EA-119: "Last dose was 2:00 pm", never the UTC hour. */
    public function test_the_as_needed_interval_block_says_the_last_dose_time_in_nz_time(): void
    {
        $this->onShift('2026-04-30 12:00', '2026-04-30 22:00');
        $prn = $this->order(['name' => 'Synthetic ibuprofen', 'is_prn' => true, 'frequency' => 'As needed', 'dose_times' => [],
            'prn_reason' => 'Pain', 'min_hours_between_doses' => 6], '2026-04-30 00:00');
        ClientMedicationAdministration::create(['client_id' => $this->client->id, 'client_medication_id' => $prn->id,
            'service_context_id' => $this->context->id, 'administered_by' => $this->worker->id,
            'administered_at' => CarbonImmutable::parse('2026-04-30 14:00', 'Pacific/Auckland')->utc(), 'status' => 'given', 'reason' => 'Pain']);
        $this->clock('2026-04-30 14:02');

        $error = $this->prn($prn, ['administered_at' => '2026-04-30T14:02:00+12:00'])->assertUnprocessable()->json('error');
        $this->assertStringContainsString('2:00 pm', $error);
        $this->assertStringNotContainsString('02:00', $error);
    }

    /** EA-147: refused at 22:05, follow up by 00:30; re-offered and taken at 00:20. */
    public function test_a_refusal_can_be_re_offered_after_midnight_while_its_follow_up_is_open(): void
    {
        $this->onShift('2026-04-30 21:00', '2026-05-01 07:00');
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dose_times' => ['22:00']], '2026-04-30 00:00');
        $this->clock('2026-04-30 22:05');
        $this->record($order, '2026-04-30T22:00:00+12:00', ['status' => 'refused', 'reason_code' => 'refused',
            'administered_at' => '2026-04-30T22:05:00+12:00', 'follow_up_due_at' => '2026-05-01T00:30:00+12:00'])->assertOk();
        $refusal = ClientMedicationAdministration::sole();

        $this->clock('2026-05-01 00:20');
        $this->actingAs($this->worker->fresh())->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id, 'scheduled_for' => '2026-04-30T22:00:00+12:00',
        ]))->assertOk()->assertJsonPath('reoffer.refusal_id', $refusal->id);

        $this->record($order, '2026-04-30T22:00:00+12:00', ['status' => 'given', 'reoffer_of_id' => $refusal->id,
            'administered_at' => '2026-05-01T00:20:00+12:00', 'late_reason' => 'out_or_asleep'])->assertOk();
        $this->assertSame($refusal->id, (int) ClientMedicationAdministration::where('status', 'given')->sole()->reoffer_of_id);
    }

    /** EA-147: past the next scheduled dose, the old refusal is no longer re-offered. */
    public function test_a_refusal_is_not_re_offered_once_the_next_dose_is_due(): void
    {
        $this->onShift('2026-04-30 21:00', '2026-05-01 23:00');
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dose_times' => ['08:00', '22:00']], '2026-04-30 00:00');
        $this->clock('2026-04-30 22:05');
        $this->record($order, '2026-04-30T22:00:00+12:00', ['status' => 'refused', 'reason_code' => 'refused',
            'administered_at' => '2026-04-30T22:05:00+12:00', 'follow_up_due_at' => '2026-05-01T09:30:00+12:00'])->assertOk();
        $refusal = ClientMedicationAdministration::sole();

        $this->clock('2026-05-01 08:10');
        $this->record($order, '2026-04-30T22:00:00+12:00', ['status' => 'given', 'reoffer_of_id' => $refusal->id,
            'administered_at' => '2026-05-01T08:10:00+12:00', 'late_reason' => 'out_or_asleep'])
            ->assertUnprocessable()->assertJsonPath('error_field', 'reoffer_of_id');
    }

    /** EA-043: at 00:10 the 22:00 late dose and the 23:30 dose still in its window are on Meds today. */
    public function test_an_evening_dose_still_due_or_late_stays_on_meds_today_after_midnight(): void
    {
        $this->onShift('2026-04-30 21:00', '2026-05-01 07:00');
        $late = $this->order(['name' => 'Synthetic late tablet', 'dose_times' => ['22:00']], '2026-04-30 00:00');
        $open = $this->order(['name' => 'Synthetic late-evening tablet', 'dose_times' => ['23:30']], '2026-04-30 00:00');
        $given = $this->order(['name' => 'Synthetic given tablet', 'dose_times' => ['21:30']], '2026-04-30 00:00');
        $this->clock('2026-04-30 21:35');
        // The hourly job has run that day, so the projection holds it.
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $this->record($given, '2026-04-30T21:30:00+12:00', ['status' => 'given', 'administered_at' => '2026-04-30T21:35:00+12:00'])->assertOk();

        $this->clock('2026-05-01 00:10');
        $rows = collect($this->actingAs($this->worker->fresh())->get('/meds/today')->assertOk()->inertiaProps('schedule'));
        $yesterday = $rows->filter(fn (array $row): bool => str_starts_with(
            CarbonImmutable::parse($row['scheduled_for'])->setTimezone('Pacific/Auckland')->toDateString(), '2026-04-30'));

        $this->assertSame(
            [[$late->id, 'overdue'], [$open->id, 'due']],
            $yesterday->map(fn (array $row): array => [$row['medication_id'], $row['status']])->sortBy(0)->values()->all(),
        );
        $this->assertTrue($yesterday->every(fn (array $row): bool => $row['from_yesterday'] === true));
        // The overdue job counts the same still-owed dose from yesterday.
        $this->assertContains(
            \App\Services\Medication\DoseSlots\OverdueDoses::key($late->id, Carbon::parse('2026-04-30 22:00', 'Pacific/Auckland')->utc()),
            app(\App\Services\Medication\DoseSlots\OverdueDoses::class)->at(now(), [$this->client->id])->pluck('key')->all(),
        );
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function order(array $attributes, string $enteredNz): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->clock($enteredNz);
        $order = ClientMedication::create([
            'client_id' => $this->client->id, 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'Daily', 'is_prn' => false, 'controlled_drug' => false, 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', ...$attributes,
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $order;
    }

    private function prn(ClientMedication $order, array $fields)
    {
        return $this->actingAs($this->worker->fresh())->postJson('/meds/today/prn', [
            'client_medication_id' => $order->id, 'client_request_uuid' => (string) Str::uuid(), 'reason' => 'Pain',
            'amount_mode' => 'as_ordered', ...$fields,
        ]);
    }

    private function record(ClientMedication $order, string $scheduledFor, array $fields)
    {
        return $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $order->id, 'client_request_uuid' => (string) Str::uuid(), 'scheduled_for' => $scheduledFor,
            'amount_mode' => ($fields['status'] ?? null) === 'given' ? 'as_ordered' : null, ...$fields,
        ]);
    }

    private function onShift(string $fromNz, string $toNz): void
    {
        Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'starts_at' => CarbonImmutable::parse($fromNz, 'Pacific/Auckland')->utc(),
            'ends_at' => CarbonImmutable::parse($toNz, 'Pacific/Auckland')->utc(),
            'actual_starts_at' => CarbonImmutable::parse($fromNz, 'Pacific/Auckland')->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();
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
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYears(2)->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'restricted' => false, 'not_seen_areas' => []]);
        $this->client->supportWorkers()->attach($user->id);

        return $user->fresh();
    }
}
