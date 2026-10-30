<?php

namespace Tests\Feature\Emar;

use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoomAlert;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientLeaveWorkflow;
use App\Services\Medication\DoseSlots\AwayReturnFollowups;
use App\Services\Medication\DoseSlots\DoseAwaySources;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\OverdueDoses;
use App\Services\Medication\MedicationSignalService;
use App\Services\Medication\OverdueDoseAlerts;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * eMAR audit Lane A8 (EA-021, EA-040, EA-078; Stephan's D6 as decided by
 * Main, 14 Oct): D6 is about a forgotten return, not a stay still going on.
 * Leave and respite stay Away through their planned end, then for the cap
 * (default 24 hours, elapsed); after that the doses are owed again and the
 * house lead gets one "Record the return" follow-up. An open hospital stay
 * stays Away and the lead is asked, after the cap, to record the discharge
 * or confirm they're still in hospital — unless set to "owed after the
 * cap". Recording the return turns the still-unrecorded doses in between
 * back to Away and closes the follow-up. All organisation settings.
 *
 * Monday 15 June 2026 (NZST, UTC+12) unless a test says otherwise. A leave
 * planned for Monday only ends at Tuesday 00:00, so it is owed from
 * Wednesday 00:00.
 */
class LaneAAwayCapTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $kowhai;

    private Client $aroha;

    private User $reader;

    private ClientMedication $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        // The schema dump records this migration as run without its rows:
        // replay it so overdue signals route to an alert (as AwayDosesTest).
        (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
        Cache::flush();
        $this->at('2026-06-12 00:00');
        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false]);
        $this->kowhai = Site::factory()->create(['name' => 'Kowhai House', 'is_active' => true, 'archived' => false]);
        $context = ServiceContext::factory()->create(['name' => 'Away cap', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->home->id]);
        $this->aroha = Client::factory()->create([
            'first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->home->id,
            'service_context_id' => $context->id, 'status' => 'active', 'suppress_med_admin_alerts' => false,
        ]);
        $this->reader = $this->staff($this->home, ['clients.viewAssigned', 'calendar.view', 'medications.view', 'clients.update', 'clinical.events.record']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
        $this->order = $this->scheduledOrder('Metformin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_leave_with_no_recorded_return_stays_away_for_the_cap_after_its_planned_end_then_is_owed(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        $this->leave('2026-06-15T08:00:00+12:00');

        // Wednesday 21:30: Monday and Tuesday are Away; Wednesday 00:00 is
        // 24 h after the planned end (Tuesday 00:00) — owed again.
        $this->at('2026-06-17 21:30');
        $this->generate();
        $this->assertSame(['away', 'away'], $this->states('2026-06-15'));
        $this->assertSame(['away', 'away'], $this->states('2026-06-16'));
        $this->assertSame(['late', 'late'], $this->states('2026-06-17'));
        $this->assertSame(['2026-06-17 08:00', '2026-06-17 20:00'], $this->overdueOn('2026-06-17'));

        // The fallback read for a day the projection doesn't hold says the same.
        $this->assertSame([], app(DoseAwaySources::class)->periods([$this->aroha->id], '2026-06-17', '2026-06-17')->get($this->aroha->id, []));
        $tuesday = app(DoseAwaySources::class)->periods([$this->aroha->id], '2026-06-16', '2026-06-16')->get($this->aroha->id);
        $this->assertTrue($tuesday[0]['no_return']);
        $this->assertNotNull(DoseAwaySources::refAt($tuesday, CarbonImmutable::parse('2026-06-16T23:59:59+12:00')->utc()));
        $this->assertNull(DoseAwaySources::refAt($tuesday, CarbonImmutable::parse('2026-06-17T00:00:00+12:00')->utc()));
    }

    public function test_planned_leave_stays_away_through_its_planned_end_and_the_cap(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        // Planned Monday to Friday: Away until Sunday 00:00 (Saturday 00:00 + 24 h).
        $this->leave('2026-06-15T08:00:00+12:00', '2026-06-19');

        $this->at('2026-06-20 21:30');
        $this->generate();
        $this->assertSame(['away', 'away'], $this->states('2026-06-20'));
        $this->assertSame([], $this->overdueOn('2026-06-20'));

        $this->at('2026-06-21 09:30');
        $this->generate();
        $this->assertSame(['late', 'not_due'], $this->states('2026-06-21'));
    }

    public function test_recording_the_return_later_turns_the_doses_in_between_back_to_away_and_closes_the_follow_up(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        $leave = $this->leave('2026-06-15T08:00:00+12:00');

        $this->at('2026-06-17 21:30');
        $this->generate();
        app(OverdueDoseAlerts::class)->sweep(now());
        app(OverdueDoseAlerts::class)->sweep(now());
        $followup = MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->sole();
        $this->assertSame((int) $this->aroha->id, (int) $followup->client_id);
        $this->assertNull($followup->client_medication_id);
        $this->assertNull($followup->owner_id, 'A house lead follow-up, not one person’s.');
        $this->assertSame('leave', $followup->context['away_source']);
        $this->assertSame(AwayReturnFollowups::RETURN_LABEL, $followup->context['label']);
        $this->assertSame('2026-06-17T00:00:00+12:00', $followup->due_at->setTimezone('Pacific/Auckland')->toIso8601String());

        $this->at('2026-06-17 21:40');
        $this->returned($leave, '2026-06-17T21:00:00+12:00');
        $this->assertSame(['away', 'away'], $this->states('2026-06-17'));
        $this->assertSame([], $this->overdueOn('2026-06-17'));

        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertNotNull($followup->fresh()->completed_at);
        $this->assertSame(1, MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->count());
    }

    /**
     * Main, 13 Oct: the person came back after the cap and nobody recorded
     * it. Staff record one dose given and another refused; later someone
     * records the return as earlier. Only the dose nobody recorded turns
     * back to Away — the given and refused records stand — and the overdue
     * alert raised for it settles.
     */
    public function test_a_return_recorded_later_keeps_what_was_recorded_and_settles_the_alert(): void
    {
        $sertraline = $this->scheduledOrder('Sertraline');
        $lithium = $this->scheduledOrder('Lithium');
        $this->at('2026-06-15 07:00');
        $this->generate();
        $leave = $this->leave('2026-06-15T08:00:00+12:00');

        // Wednesday 14:00, owed since 00:00: Wednesday 08:00 is overdue.
        $this->at('2026-06-17 14:00');
        $this->generate();
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertCount(3, $this->overdueOn('2026-06-17'));
        $this->assertNotEmpty($this->openOverdueAlerts());

        // Staff record two of them; the third is left.
        $this->record($this->order, '2026-06-17 08:00', 'given', '2026-06-17 14:00');
        $this->record($sertraline, '2026-06-17 08:00', 'refused', '2026-06-17 14:00');
        $this->assertSame(['2026-06-17 08:00'], $this->overdueOn('2026-06-17'));

        // Later the return is recorded as Wednesday 12:00.
        $this->at('2026-06-17 15:00');
        $this->returned($leave, '2026-06-17T12:00:00+12:00');
        $this->artisan('emar:send-alerts')->assertSuccessful();

        $wednesday = fn (ClientMedication $order): ?string => $this->rows('2026-06-17')
            ->where('client_medication_id', $order->id)->firstWhere('ordered_time', '08:00')['state'] ?? null;
        $this->assertSame('given', $wednesday($this->order), 'A recorded dose is never reclassified as Away.');
        $this->assertSame('refused', $wednesday($sertraline));
        $this->assertSame('away', $wednesday($lithium));
        $this->assertSame([], $this->overdueOn('2026-06-17'));
        $this->assertCount(0, $this->openOverdueAlerts(), 'The alert for the dose that is now Away settles.');
        $this->assertSame(2, ClientMedicationAdministration::query()->count());
    }

    public function test_no_follow_up_before_the_cap_or_when_switched_off(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        $this->leave('2026-06-15T08:00:00+12:00');
        $this->at('2026-06-16 23:59');
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertSame(0, MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->count());

        AppSetting::query()->updateOrCreate(['key' => DoseAwaySources::RETURN_FOLLOWUP_SETTING], ['value' => 'no']);
        $this->at('2026-06-18 09:00');
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertSame(0, MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->count());
    }

    public function test_the_cap_is_an_organisation_setting(): void
    {
        AppSetting::query()->updateOrCreate(['key' => DoseAwaySources::NO_RETURN_CAP_SETTING], ['value' => '36']);
        $this->at('2026-06-15 07:00');
        $this->generate();
        $this->leave('2026-06-15T08:00:00+12:00');
        // 36 h after the planned end (Tuesday 00:00) is Wednesday 12:00.
        $this->at('2026-06-17 21:30');
        $this->generate();
        $this->assertSame(['away', 'late'], $this->states('2026-06-17'));
    }

    /** Main, 14 Oct: a 6-day respite booking stays Away through day 6, then is owed after the cap. */
    public function test_respite_stays_away_through_its_booking_then_is_owed_after_the_cap(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        // Booked 14–21 June; checked in Monday 08:00, never discharged.
        $this->stay('2026-06-15 08:00');

        $this->at('2026-06-21 21:30');
        $this->generate();
        $this->assertSame(['away', 'away'], $this->states('2026-06-21'));
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertSame(0, MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->count());

        // Monday 22 June: 24 h after the booking's end.
        $this->at('2026-06-22 09:30');
        $this->generate();
        $this->assertSame(['late', 'not_due'], $this->states('2026-06-22'));
        app(OverdueDoseAlerts::class)->sweep(now());
        $followup = MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->sole();
        $this->assertSame('respite', $followup->context['away_source']);
        $this->assertSame(AwayReturnFollowups::RETURN_LABEL, $followup->context['label']);
    }

    /**
     * Main, 14 Oct: a hospital stay has no planned end. It stays Away while
     * the admission is open; after the cap the lead is asked to record the
     * discharge or confirm they're still in hospital, and asked again after
     * another cap once they confirm. The discharge closes it.
     */
    public function test_an_open_hospital_stay_stays_away_with_one_follow_up_until_discharged(): void
    {
        $this->at('2026-06-15 07:00');
        $this->generate();
        $this->at('2026-06-15 08:00');
        $admission = $this->admit('2026-06-15T08:00:00+12:00');

        $this->at('2026-06-16 09:30');
        app(OverdueDoseAlerts::class)->sweep(now());
        app(OverdueDoseAlerts::class)->sweep(now());
        $first = MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->sole();
        $this->assertSame('hospital', $first->context['away_source']);
        $this->assertSame(AwayReturnFollowups::HOSPITAL_LABEL, $first->context['label']);
        $this->assertSame('2026-06-16T08:00:00+12:00', $first->due_at->setTimezone('Pacific/Auckland')->toIso8601String());

        $this->at('2026-06-17 21:30');
        $this->generate();
        $this->assertSame(['away', 'away'], $this->states('2026-06-17'));
        $this->assertSame([], $this->overdueOn('2026-06-17'));

        // The house lead confirms they're still in hospital.
        $lead = $this->lead();
        $this->actingAs($lead)->postJson('/medication-followups/'.$first->id.'/transition', [
            'action' => AwayReturnFollowups::STILL_IN_HOSPITAL, 'revision' => $first->revision, 'request_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertSame('still_in_hospital', $first->fresh()->context['outcome']);

        // Asked again 24 h after the confirmation, not before.
        $this->at('2026-06-18 21:00');
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertSame(0, MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->whereNull('completed_at')->count());
        $this->at('2026-06-18 21:45');
        app(OverdueDoseAlerts::class)->sweep(now());
        $second = MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->whereNull('completed_at')->sole();
        $this->assertSame(AwayReturnFollowups::sourceKey('hospital', $admission, 2), $second->source_key);

        // The discharge closes it.
        $this->at('2026-06-19 10:30');
        $this->discharge($admission, '2026-06-19T10:00:00+12:00');
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertNotNull($second->fresh()->completed_at);
    }

    /** Main, 14 Oct: "owed after the cap" makes an open hospital stay owed like a forgotten return. */
    public function test_hospital_set_to_owed_after_the_cap_makes_its_doses_owed(): void
    {
        AppSetting::query()->updateOrCreate(['key' => DoseAwaySources::HOSPITAL_NO_DISCHARGE_SETTING], ['value' => DoseAwaySources::HOSPITAL_OWED_AFTER_CAP]);
        $this->at('2026-06-15 07:00');
        $this->generate();
        $this->at('2026-06-15 08:00');
        $this->admit('2026-06-15T08:00:00+12:00');

        $this->at('2026-06-16 21:30');
        $this->assertSame(['away', 'away'], $this->states('2026-06-15'));
        $this->assertSame(['late', 'late'], $this->states('2026-06-16'));
        app(OverdueDoseAlerts::class)->sweep(now());
        $this->assertSame('hospital', MedicationFollowup::query()->where('type', AwayReturnFollowups::TYPE)->sole()->context['away_source']);

        // The setting is stricter; switching back to the follow-up loosens.
        $definition = app(\App\Services\Medication\Settings\MedicationSettingsRegistry::class)->definition('away', 'hospital_no_discharge');
        $this->assertTrue($definition->loosens(DoseAwaySources::HOSPITAL_OWED_AFTER_CAP, DoseAwaySources::HOSPITAL_FOLLOW_UP));
        $this->assertFalse($definition->loosens(DoseAwaySources::HOSPITAL_FOLLOW_UP, DoseAwaySources::HOSPITAL_OWED_AFTER_CAP));
    }

    /**
     * The 24 hours are elapsed time from the planned end on NZ clocks.
     * Daylight saving starts on Sunday 27 September 2026 (2:00 am → 3:00 am):
     * a leave planned to end on Saturday 26 ends at Sunday 00:00 NZST, and
     * 24 h later is Monday 1:00 am NZDT. It ends on Sunday 4 April 2027
     * (3:00 am → 2:00 am): a leave planned to end on Saturday 3 ends at
     * Sunday 00:00 NZDT, and 24 h later is Sunday 11:00 pm NZST.
     */
    #[DataProvider('daylightSaving')]
    public function test_the_cap_is_elapsed_time_after_the_planned_end_across_nz_clock_changes(string $endsOn, string $awayAt, string $owedAt): void
    {
        $departed = CarbonImmutable::parse($endsOn.' 08:00', 'Pacific/Auckland');
        $owedDay = CarbonImmutable::parse($owedAt)->setTimezone('Pacific/Auckland');
        $this->at($departed->subHour()->format('Y-m-d H:i'));
        $order = ClientMedication::query()->create([
            'client_id' => $this->aroha->id, 'name' => 'Boundary dose', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => [CarbonImmutable::parse($awayAt)->setTimezone('Pacific/Auckland')->format('H:i'), $owedDay->format('H:i')],
            'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => $departed->subDays(10)->toDateString(),
        ]);
        $this->generate();
        $this->leave($departed->toIso8601String(), $endsOn);

        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], $owedDay->toDateString(), $owedDay->toDateString())->get($this->aroha->id);
        $this->assertNotNull(DoseAwaySources::refAt($periods, CarbonImmutable::parse($awayAt)->utc()));
        $this->assertNull(DoseAwaySources::refAt($periods, CarbonImmutable::parse($owedAt)->utc()));

        // The projection's SQL agrees on the same boundary (half an hour
        // later, the owed dose is in its window, still on the same NZ day).
        $this->at($owedDay->addMinutes(30)->format('Y-m-d H:i'));
        $rows = $this->rows($owedDay->toDateString())->where('client_medication_id', $order->id)->sortBy('due_at')->values();
        $this->assertSame(['away', 'due'], $rows->pluck('state')->all());
    }

    public static function daylightSaving(): array
    {
        return [
            'clocks go forward' => ['2026-09-26', '2026-09-28T00:30:00+13:00', '2026-09-28T01:00:00+13:00'],
            'clocks go back' => ['2027-04-03', '2027-04-04T22:30:00+12:00', '2027-04-04T23:00:00+12:00'],
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** The departure is recorded as it happens (an actual time is never a future plan). */
    private function leave(string $departure, ?string $endsOn = null): ClientLeaveRequest
    {
        $workflow = app(ClientLeaveWorkflow::class);
        $starts = CarbonImmutable::parse($departure)->setTimezone('Pacific/Auckland')->toDateString();
        $leave = $workflow->create($this->reader, $this->aroha, [
            'starts_on' => $starts, 'ends_on' => $endsOn ?? $starts, 'status' => 'approved',
        ]);
        $now = Carbon::getTestNow();
        $departed = Carbon::parse($departure)->utc();
        if ($departed->greaterThan($now)) {
            Carbon::setTestNow($departed);
            CarbonImmutable::setTestNow(CarbonImmutable::instance($departed));
        }
        try {
            return $workflow->transition($this->reader, $this->aroha, $leave,
                ['action' => 'depart', 'version' => $leave->version, 'occurred_at' => $departure]);
        } finally {
            Carbon::setTestNow($now);
            CarbonImmutable::setTestNow(CarbonImmutable::instance($now));
        }
    }

    private function returned(ClientLeaveRequest $leave, string $at): void
    {
        app(ClientLeaveWorkflow::class)->transition($this->reader, $this->aroha, $leave->fresh(),
            ['action' => 'return', 'version' => $leave->fresh()->version, 'occurred_at' => $at]);
    }

    private function admit(string $at): int
    {
        return (int) app(ClinicalEventService::class)->record($this->aroha, $this->reader, [
            'event_type' => 'hospital_admission', 'severity' => 'medium', 'description' => 'Actual hospital attendance',
            'occurred_at' => $at,
        ])->id;
    }

    private function discharge(int $admissionId, string $at): void
    {
        app(ClinicalEventService::class)->record($this->aroha, $this->reader, [
            'event_type' => 'hospital_discharge', 'severity' => 'low', 'description' => 'Discharged home',
            'occurred_at' => $at, 'hospital_admission_id' => $admissionId,
        ]);
    }

    private function stay(string $startNz): RespiteStay
    {
        $booking = DB::transaction(fn (): RespiteBooking => RespiteBooking::query()->create([
            'client_id' => $this->aroha->id, 'location_id' => $this->kowhai->id,
            'start_at' => Carbon::parse('2026-06-14 00:00', 'Pacific/Auckland')->utc(),
            'end_at' => Carbon::parse('2026-06-21 00:00', 'Pacific/Auckland')->utc(),
            'status' => 'in_progress',
        ]));

        return DB::transaction(fn (): RespiteStay => RespiteStay::query()->create([
            'booking_id' => $booking->id, 'client_id' => $this->aroha->id, 'status' => 'active',
            'actual_start' => Carbon::parse($startNz, 'Pacific/Auckland')->utc(),
        ]));
    }

    private function scheduledOrder(string $name): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->aroha->id, 'name' => $name, 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['08:00', '20:00'], 'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-06-01',
        ]);
    }

    private function record(ClientMedication $order, string $dueNz, string $status, string $atNz): ClientMedicationAdministration
    {
        return DB::transaction(fn (): ClientMedicationAdministration => ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => $this->reader->id,
            'scheduled_for' => Carbon::parse($dueNz, 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse($atNz, 'Pacific/Auckland')->utc(),
            'status' => $status,
            'reason' => $status === 'given' ? null : 'Declined.',
        ]));
    }

    /** @return Collection<int, ControlRoomAlert> */
    private function openOverdueAlerts(): Collection
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->get();
    }

    private function at(string $nz): void
    {
        $at = Carbon::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow(CarbonImmutable::instance($at));
    }

    /** Today and the next two NZ days, as the hourly job keeps them. */
    private function generate(): void
    {
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
    }

    /** @return list<string> */
    private function states(string $nzDate): array
    {
        return $this->rows($nzDate)->where('client_medication_id', $this->order->id)->sortBy('due_at')->pluck('state')->values()->all();
    }

    /** @return list<string> */
    private function overdueOn(string $nzDate): array
    {
        return app(OverdueDoses::class)->at(now(), [$this->aroha->id])
            ->map(fn (array $dose): string => $dose['due_at']->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
            ->filter(fn (string $due): bool => str_starts_with($due, $nzDate))
            ->values()->all();
    }

    private function rows(string $nzDate)
    {
        return app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->aroha->id]), $nzDate, $nzDate, CarbonImmutable::now());
    }

    /** A house lead who signs off lead follow-ups at Aurora House. */
    private function lead(): User
    {
        $user = $this->staff($this->home, ['medications.view', 'medications.administer.record', 'medications.followups.manage', 'clients.viewAny']);
        $user->forceFill(['role' => 'team_lead'])->save();
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->value('id')]);

        return $user->fresh();
    }

    private function staff(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true,
        ]);

        return $user->fresh();
    }
}
