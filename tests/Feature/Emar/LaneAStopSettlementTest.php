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
use App\Services\Medication\DoseSlots\OverdueDoses;
use App\Services\Medication\MedicationOrderLifecycleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\RecordsDosesLikeTheDialog;

/**
 * eMAR audit Lane A9 (EA-041): stopping or pausing an order doesn't settle a
 * dose that was already due or overdue before the stop. It stays owed until
 * someone records what happened; doses due after the stop are not owed.
 */
class LaneAStopSettlementTest extends TestCase
{
    use RefreshDatabase;
    use RecordsDosesLikeTheDialog;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $lead;

    private ClientMedication $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 06:00');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->worker = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);
        $this->lead = $this->staff('team_lead', ['medications.view', 'medications.administer.record', 'medications.orders.manage']);
        $this->client->supportWorkers()->attach($this->worker->id);
        $this->clock('2026-04-29 00:00');
        $this->order = ClientMedication::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic amlodipine 5mg', 'dosage' => '1 tablet', 'dose_amount' => 1,
            'dose_unit' => 'tablet', 'frequency' => 'Daily', 'dose_times' => ['08:00'], 'is_prn' => false, 'controlled_drug' => false,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-04-01',
        ]);
        // 10:00: the 08:00 dose (window to 09:00) is overdue.
        $this->clock('2026-04-30 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_ceasing_an_order_keeps_the_overdue_morning_dose_owed_until_it_is_recorded(): void
    {
        $key = OverdueDoses::key($this->order->id, Carbon::parse('2026-04-30 08:00', 'Pacific/Auckland'));
        $this->assertContains($key, app(OverdueDoses::class)->at(now())->pluck('key')->all());

        $this->clock('2026-04-30 10:05');
        $this->actingAs($this->lead->fresh());
        app(MedicationOrderLifecycleService::class)->discontinue($this->lead->fresh(), $this->order->fresh(), 'GP stopped it after a review', $this->client->id);
        $this->clock('2026-04-30 10:10');

        $this->assertSame([], app(OverdueDoses::class)->settled([$key], now()), 'The 08:00 dose was settled by the stop.');
        $this->assertContains($key, app(OverdueDoses::class)->at(now())->pluck('key')->all());
        $rows = collect($this->actingAs($this->worker->fresh())->get('/meds/today')->assertOk()->inertiaProps('schedule'))
            ->where('medication_id', $this->order->id)
            // Today's dose; yesterday's still-owed doses (EA-043) are left out.
            ->reject(fn (array $row): bool => $row['from_yesterday'] ?? false);
        $this->assertSame(['overdue'], $rows->pluck('status')->values()->all());

        // Main, 14 Oct: "given" at a time after the stop is refused, nothing written.
        $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $this->order->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T08:00:00+12:00', 'status' => 'given', 'administered_at' => '2026-04-30T10:10:00',
            'amount_mode' => 'as_ordered', 'late_reason' => array_key_first(\App\Services\Medication\Recording\RecordingContract::LATE_REASONS),
        ])->assertUnprocessable()->assertJsonPath('error_field', 'client_medication_id');
        $this->assertSame(0, ClientMedicationAdministration::count());

        // What happened can still be recorded for the dose owed before the stop.
        $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $this->order->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T08:00:00+12:00', 'status' => 'withheld', 'reason_code' => 'other',
            'notes' => 'Not given before the GP stopped it',
        ])->assertOk();
        $this->assertSame([$key], app(OverdueDoses::class)->settled([$key], now()));

        // Tomorrow's dose, due after the stop, is not owed.
        $this->clock('2026-05-01 10:00');
        $this->assertSame([], app(OverdueDoses::class)->at(now())->pluck('key')->all());
    }

    /**
     * Main, 14 Oct: a dose due AFTER the stop is not owed: recording it is
     * refused, with nothing written, on Meds today, through the mobile API
     * and as a dose replayed from a device.
     */
    public function test_a_dose_due_after_the_stop_is_refused_everywhere_with_nothing_written(): void
    {
        $this->clock('2026-04-30 10:05');
        $this->actingAs($this->lead->fresh());
        app(MedicationOrderLifecycleService::class)->discontinue($this->lead->fresh(), $this->order->fresh(), 'GP stopped it after a review', $this->client->id);
        $this->clock('2026-05-01 08:30');
        $tomorrow = ['client_medication_id' => $this->order->id, 'scheduled_for' => '2026-05-01T08:00:00+12:00',
            'status' => 'withheld', 'reason_code' => 'other', 'notes' => 'Stopped yesterday'];

        $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [...$tomorrow, 'client_request_uuid' => (string) Str::uuid()])
            ->assertNotFound();
        $this->postJson("/api/medications/clients/{$this->client->id}/medications/{$this->order->id}/administrations",
            [...$tomorrow, 'client_request_uuid' => (string) Str::uuid()])->assertNotFound();
        $this->postJson('/meds/today/record', [...$tomorrow, 'client_request_uuid' => (string) Str::uuid(), 'queued_offline' => true,
            'captured_offline_at' => CarbonImmutable::now('Pacific/Auckland')->toIso8601String(), 'origin_device_id' => 'synthetic-house-laptop',
            'captured_by_user_id' => $this->worker->id])->assertNotFound();
        $this->assertSame(0, ClientMedicationAdministration::count());
    }

    /**
     * Main, 14 Oct: a dose owed before the stop still goes through the person
     * rule and the controlled rule. Someone who can't record for the person,
     * or can't record a controlled medicine, gets exactly the answer they get
     * for an order still in effect — nothing says the stopped dose exists.
     */
    public function test_a_dose_owed_before_the_stop_keeps_the_person_and_controlled_rules(): void
    {
        // Compare the bodies as production renders them (no debug trace).
        config()->set('app.debug', false);
        // Entered before the 08:00 doses, like the stopped order, so each owes one.
        $this->clock('2026-04-30 06:00');
        $controlledFields = [
            'client_id' => $this->client->id, 'dosage' => '5 mg', 'dose_amount' => 5, 'dose_unit' => 'mg',
            'frequency' => 'Daily', 'dose_times' => ['08:00'], 'is_prn' => false, 'controlled_drug' => true, 'nz_controlled_class' => 'B',
            'controlled_class_source' => 'Synthetic reviewed test class', 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'start_date' => '2026-04-01',
        ];
        $controlled = ClientMedication::create([...$controlledFields, 'name' => 'Synthetic morphine 5mg']);
        $controlledInEffect = ClientMedication::create([...$controlledFields, 'name' => 'Synthetic oxycodone 5mg']);
        $stillOn = ClientMedication::create([
            'client_id' => $this->client->id, 'name' => 'Synthetic folic acid', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'Daily', 'dose_times' => ['08:00'], 'is_prn' => false, 'controlled_drug' => false, 'active' => true, 'state' => 'active',
            'approval_status' => 'verified', 'start_date' => '2026-04-01',
        ]);
        $this->clock('2026-04-30 10:05');
        foreach ([$this->order, $controlled] as $order) {
            $order->forceFill(['state' => 'ceased', 'active' => false, 'ceased_at' => now(), 'ceased_by' => $this->lead->id, 'ceased_reason' => 'GP stopped it'])->save();
        }
        $this->clock('2026-04-30 10:10');
        $withheld = fn (ClientMedication $order): array => ['client_medication_id' => $order->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T08:00:00+12:00', 'status' => 'withheld', 'reason_code' => 'other', 'notes' => 'Not given'];

        // Someone at the house with no current work with this person: the
        // same "no current assignment" as for an order in effect.
        $outsider = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);
        Shift::query()->where('user_id', $outsider->id)->delete();
        Cache::flush();
        $inEffect = $this->actingAs($outsider->fresh())->postJson('/meds/today/record', $withheld($stillOn))->assertForbidden();
        $stopped = $this->actingAs($outsider->fresh())->postJson('/meds/today/record', $withheld($this->order))->assertForbidden();
        $this->assertSame($inEffect->json(), $stopped->json());

        // Someone at another house: the same 404 for both.
        $elsewhere = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $stranger = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);
        Shift::query()->where('user_id', $stranger->id)->delete();
        \App\Domain\Hr\Models\HrEmployeeProfile::query()->where('user_id', $stranger->id)->update(['primary_site_id' => $elsewhere->id]);
        Cache::flush();
        $inEffect = $this->actingAs($stranger->fresh())->postJson('/meds/today/record', $withheld($stillOn))->assertNotFound();
        $stopped = $this->actingAs($stranger->fresh())->postJson('/meds/today/record', $withheld($this->order))->assertNotFound();
        $this->assertSame($inEffect->json(), $stopped->json());

        // A worker whose controlled-record permission is withdrawn.
        $this->worker->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'medications.controlled.record')->sole()->id => ['allowed' => false],
        ]);
        Cache::flush();
        $a = $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', $withheld($controlledInEffect))->assertNotFound();
        $b = $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', $withheld($controlled))->assertNotFound();
        $this->assertSame($a->json(), $b->json());
        $this->assertSame(0, ClientMedicationAdministration::count());
    }

    public function test_pausing_an_order_keeps_the_overdue_morning_dose_owed(): void
    {
        $key = OverdueDoses::key($this->order->id, Carbon::parse('2026-04-30 08:00', 'Pacific/Auckland'));
        $this->order->forceFill(['state' => 'paused', 'paused_at' => now(), 'active' => false])->save();
        $this->clock('2026-04-30 10:10');

        $this->assertSame([], app(OverdueDoses::class)->settled([$key], now()));
        $rows = collect($this->actingAs($this->worker->fresh())->get('/meds/today')->assertOk()->inertiaProps('schedule'))
            ->where('medication_id', $this->order->id)
            // Today's dose; yesterday's still-owed doses (EA-043) are left out.
            ->reject(fn (array $row): bool => $row['from_yesterday'] ?? false);
        $this->assertSame(['overdue'], $rows->pluck('status')->values()->all());

        // "Not given" is accepted after the pause too.
        $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $this->order->id, 'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T08:00:00+12:00', 'status' => 'refused', 'reason_code' => 'refused',
            'follow_up_due_at' => '2026-04-30T11:00:00',
        ])->assertOk();
        $this->assertSame('refused', ClientMedicationAdministration::sole()->status);
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    /** @param  list<string>  $keys */
    private function staff(string $role, array $keys): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', $role)->sole()->id]);
        $permissions = Permission::whereIn('key', $keys)->get();
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->utc(), 'ends_at' => now()->addDays(2)->utc(),
            'actual_starts_at' => now()->addMinutes(5)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
