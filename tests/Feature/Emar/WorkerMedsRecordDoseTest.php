<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Incidents\IncidentJourneyService;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Desktop medication board — scheduled-dose recording (Record Dose wizard),
 * PRN follow-up effects, and the extended board payload.
 */
class WorkerMedsRecordDoseTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = $this->makeRoleUser('support_worker');
        $this->grantPermissions($this->worker, ['medications.administer.record']);
        $this->site = Site::factory()->create(['is_active' => true]);
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
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ]);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Record Dose',
            'type' => 'residential',
            'is_active' => true,
        ]);

        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
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

    public function test_worker_records_a_scheduled_dose_as_given(): void
    {
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
                'notes' => 'Taken with breakfast.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::sole();
        $this->assertSame('given', $administration->status);
        $this->assertSame($this->worker->id, (int) $administration->administered_by);
        $this->assertSame('1 tablet', $administration->dose_given);
        $this->assertSame(
            $scheduledFor->copy()->utc()->format('Y-m-d H:i'),
            Carbon::parse((string) $administration->getRawOriginal('scheduled_for'), 'UTC')->format('Y-m-d H:i'),
        );

        // The recording lands on the client timeline (same emission as the
        // guided round path), which also feeds the board's activity feed.
        $this->assertDatabaseHas('timeline_events', [
            'type' => 'medication_given',
            'source_type' => ClientMedicationAdministration::class,
            'source_id' => $administration->id,
            'client_id' => $this->client->id,
            'actor_user_id' => $this->worker->id,
        ]);

        // The board now shows the slot as recorded, the activity feed
        // carries the event, and the sidebar overdue badge reads zero.
        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.0.status', 'given')
                ->where('schedule.0.recorded.by', $this->worker->name)
                ->count('activity', 1)
                ->where('activity.0.icon', 'check')
                ->where('auth.can.medications.overdueTodayCount', 0)
            );
    }

    public function test_late_recording_outside_the_window_requires_a_reason(): void
    {
        $medication = $this->scheduledMedication(['08:00']);
        $scheduledFor = Carbon::parse('2026-04-30 08:00', config('app.worker_timezone'));

        // 09:30 is 90 minutes after the 08:00 slot — outside the default
        // 60-minute window, so the MAR pipeline demands a reason.
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'reason' => 'Client was out at an appointment — given on return.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_refused_dose_requires_a_not_given_reason_code(): void
    {
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'refused',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('reason_code');

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'refused',
                'reason_code' => 'refused',
                'reason' => 'Declined, offered again at 9:40 and declined again.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'refused',
            'reason_code' => 'refused',
        ]);
    }

    public function test_offline_refusal_rolls_back_a_failed_incident_hook_then_replays_without_duplicates(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'high_risk' => true,
            'controlled_drug' => false,
            'witness_required' => false,
        ]);
        $signals = new class(app(SignalProcessingService::class)) extends MedicationSignalService
        {
            public int $attempts = 0;

            public function emit(
                string $signalType,
                int $clientId,
                string $severity,
                string $message,
                array $context = [],
                bool $requiredDelivery = false,
            ): void {
                if ($signalType === self::TYPE_REFUSED_DOSE && $this->attempts++ === 0) {
                    throw new \RuntimeException('Forced worker refusal hook failure');
                }

                parent::emit($signalType, $clientId, $severity, $message, $context, $requiredDelivery);
            }
        };
        $this->app->instance(
            MedicationIncidentIntegrationService::class,
            new MedicationIncidentIntegrationService(
                $signals,
                app(IncidentJourneyService::class),
            ),
        );
        $requestUuid = 'worker-refusal-hook-repair';
        $payload = [
            'client_medication_id' => $medication->id,
            'scheduled_for' => now()->toIso8601String(),
            'status' => 'refused',
            'reason_code' => 'refused',
            'reason' => 'Client declined after a second offer.',
            'administered_at' => now()->toIso8601String(),
            'client_request_uuid' => $requestUuid,
            'captured_offline_at' => now()->toIso8601String(),
            'origin_device_id' => 'worker-meds-board',
            'queued_offline' => true,
        ];
        $this->withoutExceptionHandling();
        $firstException = null;

        try {
            $this->actingAs($this->worker)
                ->from('/meds/today')
                ->post('/meds/today/record', $payload);
        } catch (\Throwable $caught) {
            $firstException = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $firstException);
        $this->assertSame('Forced worker refusal hook failure', $firstException->getMessage());
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_incidents', 0);
        $this->assertDatabaseCount('control_room_signals', 0);
        $this->assertDatabaseCount('control_room_alerts', 0);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');
        Cache::forget("offline:idempotency:dose:{$requestUuid}");
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', $payload)
            ->assertRedirect('/meds/today')
            ->assertSessionHas('warning');

        $this->assertSame(3, $signals->attempts);
        $this->assertSame(
            $requestUuid,
            ClientMedicationAdministration::query()->sole()->client_request_uuid,
        );
        $this->assertSame(
            'refused_dose',
            data_get(ClientIncident::query()->sole()->metadata, 'medication_incident_source.kind'),
        );
        $this->assertSame(MedicationSignalService::TYPE_REFUSED_DOSE, Signal::query()->sole()->signal_type_code);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('client_incidents', 1);
        $this->assertDatabaseCount('control_room_signals', 1);
        $this->assertDatabaseCount('control_room_alerts', 1);
        $this->assertSame(
            ClientIncident::query()->sole()->id,
            (int) data_get(ControlRoomAlert::query()->sole()->context, 'incident_id'),
        );
    }

    public function test_withheld_dose_accepts_the_omitted_in_error_reason_code(): void
    {
        // A genuinely-missed dose is recorded as withheld with the structured
        // "Omitted in error" reason (NotGivenReason::OmittedInError) — the code
        // is validated against the enum, so this proves it is accepted end-to-end.
        $medication = $this->scheduledMedication(['09:30']);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'withheld',
                'reason_code' => 'omitted_in_error',
                'reason' => 'Missed on the morning round — escalated to the RN, resident unaffected.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('client_medication_administrations', [
            'client_medication_id' => $medication->id,
            'status' => 'withheld',
            'reason_code' => 'omitted_in_error',
        ]);
    }

    public function test_controlled_dose_requires_witness_and_writes_register_entry(): void
    {
        $medication = $this->scheduledMedication(['09:30'], [
            'name' => 'Methylphenidate',
            'controlled_drug' => true,
        ]);
        ClientMedicationStock::create([
            'client_medication_id' => $medication->id,
            'on_hand' => 10,
            'unit' => 'tablets',
        ]);
        $scheduledFor = Carbon::parse('2026-04-30 09:30', config('app.worker_timezone'));

        // No witness → blocked by the shared witness validation.
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors('witnessed_by');

        $witness = $this->makeRoleUser('support_worker');
        $this->grantPermissions($witness, ['medications.controlled.witness']);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $medication->id,
                'scheduled_for' => $scheduledFor->toIso8601String(),
                'status' => 'given',
                'witnessed_by' => $witness->id,
                'witness_credential' => 'password',
                'cd_balance' => 9,
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $administration = ClientMedicationAdministration::sole();
        $this->assertSame($witness->id, (int) $administration->witnessed_by);
        $this->assertStringContainsString(
            'CD register balance after dose: 9',
            (string) $administration->notes,
        );

        $this->assertDatabaseHas('client_controlled_drug_entries', [
            'client_medication_id' => $medication->id,
            'entry_type' => 'administered',
            'on_hand_after' => 9,
            'witnessed_by' => $witness->id,
        ]);
    }

    public function test_prn_effect_records_once_per_administration(): void
    {
        $prn = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol PRN',
            'dosage' => '500mg',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'prn_reason' => 'Pain',
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
        ]);

        $administration = ClientMedicationAdministration::create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'administered_by' => $this->worker->id,
            'administered_at' => now()->subHour(),
            'status' => 'given',
        ]);

        // The board surfaces the un-checked PRN as a follow-up.
        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prn_follow_ups.0.administration_id', $administration->id)
            );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn/effect', [
                'client_medication_administration_id' => $administration->id,
                'effectiveness' => 'effective',
                'observations' => 'Settled within half an hour.',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'effective',
            'reviewed_by' => $this->worker->id,
        ]);

        // Re-recording revises the single register entry (updateOrCreate keyed
        // on the administration) rather than blocking or duplicating it — the
        // eMAR "Re-record effectiveness" action. (Updated: the duplicate path now
        // succeeds with a revise message instead of the old "warning" no-op.)
        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/prn/effect', [
                'client_medication_administration_id' => $administration->id,
                'effectiveness' => 'not_effective',
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        // Still one row — now updated to the revised effectiveness.
        $this->assertDatabaseCount('medication_prn_effectiveness', 1);
        $this->assertDatabaseHas('medication_prn_effectiveness', [
            'client_medication_administration_id' => $administration->id,
            'effectiveness' => 'not_effective',
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->count('prn_follow_ups', 0));
    }

    public function test_board_payload_includes_schedule_and_supports_date_navigation(): void
    {
        $this->scheduledMedication(['08:00', '16:00']);

        $witness = $this->makeRoleUser('support_worker');
        $this->grantPermissions($witness, ['medications.controlled.witness']);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('is_today', true)
                ->count('schedule', 2)
                ->where('schedule.0.status', 'overdue')
                ->where('schedule.0.time', '08:00')
                ->where('schedule.0.round_label', 'Morning')
                ->where('schedule.1.status', 'upcoming')
                ->where('clients.0.name', 'Aroha Ngata')
                ->where('witnesses', fn ($witnesses) => $witnesses->contains('id', $witness->id))
                ->where('has_shift_context', true)
                // Sidebar badge shared prop: the 08:00 slot is overdue at
                // 09:30, the 16:00 one isn't.
                ->where('auth.can.medications.overdueTodayCount', 1)
            );

        // Day navigation: a shift tomorrow gives the worker tomorrow's board.
        // "Tomorrow" must be computed in the worker timezone — now() is UTC
        // and at 09:30 NZ the UTC date is still yesterday.
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        $tomorrow = Carbon::now($timezone)->addDay()->toDateString();

        Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => Carbon::parse($tomorrow.' 09:00', $timezone)->utc(),
            'ends_at' => Carbon::parse($tomorrow.' 17:00', $timezone)->utc(),
            'status' => 'scheduled',
        ]);

        $this->actingAs($this->worker)
            ->get('/meds/today?date='.$tomorrow)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_today', false)
                ->where('date', $tomorrow)
                ->count('schedule', 2)
                ->where('schedule.0.status', 'upcoming')
            );
    }

    private function scheduledMedication(array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ], $overrides));
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
