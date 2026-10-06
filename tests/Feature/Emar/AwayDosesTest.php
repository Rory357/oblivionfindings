<?php

namespace Tests\Feature\Emar;

use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientLeaveRequest;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoomAlert;
use App\Models\MedicationRound;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientLeaveWorkflow;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\Medication\DoseSlots\DoseAwaySources;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\DoseSlots\OverdueDoses;
use App\Services\Medication\DoseSlots\ScheduledDoseStates;
use App\Services\Medication\MedicationSignalService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * C7: a dose is Away only with positive evidence the person is elsewhere at
 * its due time — checked in at respite at another Site, from the stay's
 * actual start until its discharge (Main, 3 Oct). The booking's planned
 * times are never read. A dose due before the person left is owed, overdue
 * included. Away reads "Away · reason" everywhere — the respite house named
 * only to a reader who may access its Site — is never due, late, overdue,
 * badged or alerted, and counts on its own. Withdraw the record and the dose
 * is owed again. A recorded outcome always wins. Actual approved leave and
 * paired hospital attendance use the same boundaries in live and fallback reads.
 *
 * Monday 15 June 2026 (NZST, UTC+12); orders entered Friday 12 June.
 */
class AwayDosesTest extends TestCase
{
    use RefreshDatabase;

    private Site $home;

    private Site $kowhai;

    private Client $aroha;

    private User $reader;

    private ClientMedication $metformin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        // The schema dump records this migration as run without its rows:
        // replay it so overdue signals route to an alert.
        (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
        Cache::flush();
        $this->at('2026-06-12 00:00');
        $this->home = Site::factory()->create(['name' => 'Aurora House', 'is_active' => true, 'archived' => false]);
        $this->kowhai = Site::factory()->create(['name' => 'Kowhai House', 'is_active' => true, 'archived' => false]);
        $context = ServiceContext::factory()->create(['name' => 'Away', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->home->id]);
        $this->aroha = Client::factory()->create([
            'first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->home->id,
            'service_context_id' => $context->id, 'status' => 'active', 'suppress_med_admin_alerts' => false,
        ]);
        $this->reader = $this->staff($this->home, ['clients.viewAssigned', 'calendar.view', 'medications.view']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
        $this->metformin = ClientMedication::query()->create([
            'client_id' => $this->aroha->id,
            'name' => 'Metformin',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => ['08:00', '20:00'],
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'start_date' => '2026-06-01',
        ]);
        $this->at('2026-06-15 00:00');
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_dose_during_a_checked_in_stay_elsewhere_is_away_everywhere_and_never_alerted(): void
    {
        $stay = $this->stay('2026-06-15 07:00');
        $this->at('2026-06-15 09:30');
        $reason = 'Respite at another house (since Mon 15 Jun, 7:00 am)';

        // The projection: away, by the stay; its own number in P09.
        $rows = $this->rows('2026-06-15');
        $this->assertSame(['away', 'away'], $rows->pluck('state')->all());
        $this->assertSame(['source' => 'respite', 'id' => $stay->id], $rows->first()['away']);
        $totals = app(DoseSlotProjection::class)->totals(DoseSlotReaderScope::internal([$this->aroha->id]), '2026-06-15', '2026-06-15', CarbonImmutable::now());
        $this->assertSame([2, 0, 0, null], [$totals['away'], $totals['due'], $totals['not_recorded'], $totals['given_rate']]);

        // Meds today: shown, away with its reason, not due or overdue.
        $schedule = $this->medsToday('2026-06-15');
        $this->assertSame(['away', 'away'], array_column($schedule, 'status'));
        $this->assertSame($reason, $schedule[0]['away_reason']);

        // The MAR: "Away · reason", never overdue.
        $this->actingAs($this->reader->fresh());
        $mar = app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse('2026-06-15'), null, null, true)['scheduled'];
        $this->assertSame(['away', 'away'], array_column($mar, 'schedule_state'));
        $this->assertSame('Away · '.$reason, $mar[0]['schedule_state_label']['label']);
        $this->assertFalse($mar[0]['is_overdue']);

        // The profile calendar.
        $this->assertSame(
            ['Metformin — Away · '.$reason, 'Metformin — Away · '.$reason],
            collect($this->calendar('2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'))->pluck('title')->all(),
        );

        // Never alerted (Sunday's unrecorded doses, before the stay, still are).
        $this->assertSame([], $this->overdueOn('2026-06-15'));
        $this->assertSame(['2026-06-14 08:00', '2026-06-14 20:00'], $this->overdueOn('2026-06-14'));
    }

    public function test_the_respite_house_is_named_only_to_a_reader_who_may_access_its_site(): void
    {
        $this->stay('2026-06-15 07:00');
        $this->at('2026-06-15 09:30');
        $kowhaiLead = $this->staff($this->kowhai, ['medications.view']);
        $states = app(ScheduledDoseStates::class);
        $reason = fn (?User $viewer): ?string => $states->withAwayReasons(
            $states->dosesOn([$this->metformin->fresh()], Carbon::parse('2026-06-15', 'Pacific/Auckland'), now()),
            $viewer,
        )[$this->metformin->id][0]['away_reason'];

        $this->assertSame('Respite at another house (since Mon 15 Jun, 7:00 am)', $reason($this->reader));
        $this->assertSame('Respite at Kowhai House (since Mon 15 Jun, 7:00 am)', $reason($kowhaiLead));
        $this->assertSame('Respite at another house (since Mon 15 Jun, 7:00 am)', $reason(null));
        // Without asking, no words are loaded.
        $this->assertNull($states->dosesOn([$this->metformin->fresh()], Carbon::parse('2026-06-15', 'Pacific/Auckland'), now())[$this->metformin->id][0]['away_reason']);
    }

    public function test_a_dose_due_before_check_in_stays_owed_and_its_alert_stays_open(): void
    {
        $this->at('2026-06-15 09:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertSame(['2026-06-15 08:00'], $this->overdueOn('2026-06-15'));
        $this->assertCount(1, $this->openAlerts());

        // Checked in at 15:00, after the 08:00 dose was overdue.
        $this->at('2026-06-15 15:00');
        $this->stay('2026-06-15 15:00');
        $this->artisan('emar:send-alerts')->assertSuccessful();

        $this->assertSame(['late', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['2026-06-15 08:00'], $this->overdueOn('2026-06-15'));
        $this->assertCount(1, $this->openAlerts());
    }

    public function test_the_alert_follows_the_stay_through_its_model_hooks(): void
    {
        // Every other dose in the look-back recorded: only 20:00 is overdue.
        $this->record('2026-06-14 08:00', 'given');
        $this->record('2026-06-14 20:00', 'given');
        $this->record('2026-06-15 08:00', 'given');
        $this->at('2026-06-15 21:30');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertCount(1, $this->openAlerts());

        // A stay recorded as begun at 19:00: the 20:00 dose is away, the
        // alert settles straight away (no sweep).
        $stay = $this->stay('2026-06-15 19:00');
        $this->assertSame(['given', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertCount(0, $this->openAlerts());

        // The stay withdrawn: owed again and overdue at once; the overdue
        // job's next run raises a new alert (as when a record is withdrawn).
        DB::transaction(fn () => $stay->delete());
        $this->assertSame(['given', 'late'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['2026-06-15 20:00'], $this->overdueOn('2026-06-15'));
        $this->at('2026-06-15 21:45');
        $this->artisan('emar:send-alerts')->assertSuccessful();
        $this->assertCount(1, $this->openAlerts());
        $this->assertSame(2, $this->overdueAlertCount());
    }

    public function test_an_early_discharge_ends_away_there_and_then(): void
    {
        $stay = $this->stay('2026-06-15 07:00');
        $this->at('2026-06-15 12:00');
        DB::transaction(fn () => $stay->update(['status' => 'discharged', 'actual_end' => now()]));
        $this->at('2026-06-15 12:30');

        $this->assertSame(['away', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
    }

    public function test_no_dose_is_away_without_a_checked_in_stay_at_another_site(): void
    {
        // A confirmed booking not yet checked in (its planned times cover the day).
        $this->booking($this->kowhai->id, 'confirmed');
        // A cancelled booking whose stay was admitted but never checked in.
        $this->stay('2026-06-15 07:00', status: 'admitted', bookingStatus: 'cancelled');
        // A no-show.
        $this->booking($this->kowhai->id, 'no_show');
        // Respite at the person's own Site, and with no Site recorded.
        $this->stay('2026-06-15 07:00', siteId: $this->home->id);
        $this->stay('2026-06-15 07:00', siteId: null);
        $this->at('2026-06-15 09:30');

        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['overdue', 'upcoming'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public function test_a_refusal_recorded_during_away_wins(): void
    {
        $this->stay('2026-06-15 07:00');
        $this->record('2026-06-15 08:00', 'refused');
        $this->at('2026-06-15 09:30');

        $this->assertSame(['refused', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['refused', 'away'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public function test_planned_legacy_leave_never_establishes_away_even_when_leave_is_enabled(): void
    {
        ClientLeaveRequest::query()->create([
            'client_id' => $this->aroha->id,
            'starts_on' => '2026-06-15',
            'ends_on' => '2026-06-16',
            'status' => 'approved',
            'requested_by' => $this->reader->id,
        ]);
        $this->at('2026-06-15 09:30');

        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());

        config(['medications.away.from_leave' => true]);
        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
    }

    public function test_actual_leave_uses_departure_and_early_return_everywhere_without_changing_planned_dates(): void
    {
        $this->at('2026-06-15 09:30');
        $leave = $this->leave('2026-06-15T07:00:00+12:00');
        $reason = 'On leave (since Mon 15 Jun, 7:00 am)';
        $this->assertSame(['away', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['source' => 'leave', 'id' => $leave->id], $this->rows('2026-06-15')->first()['away']);
        $schedule = $this->medsToday('2026-06-15');
        $this->assertSame(['away', 'away'], array_column($schedule, 'status'));
        $this->assertSame($reason, $schedule[0]['away_reason']);
        $this->assertSame(array_fill(0, 2, 'Metformin — Away · '.$reason), collect($this->calendar(
            '2026-06-15T00:00:00+12:00', '2026-06-16T00:00:00+12:00'))->pluck('title')->all());
        $this->assertSame([], $this->overdueOn('2026-06-15'));
        $this->at('2026-06-15 12:30');
        app(ClientLeaveWorkflow::class)->transition($this->reader, $this->aroha, $leave,
            ['action' => 'return', 'version' => $leave->version, 'occurred_at' => '2026-06-15T12:00:00+12:00']);
        $this->assertSame('2026-06-20', $leave->fresh()->ends_on->toDateString());
        $this->assertSame(['away', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['away', 'upcoming'], array_column($this->medsToday('2026-06-15'), 'status'));
        $this->assertFalse(collect($this->calendar('2026-06-18T00:00:00+12:00', '2026-06-19T00:00:00+12:00'))
            ->contains(fn (array $event): bool => str_contains($event['title'], 'Away')));
    }

    public function test_actual_departure_does_not_settle_an_earlier_overdue_dose_and_recorded_outcomes_still_win(): void
    {
        $this->at('2026-06-15 15:30');
        $this->leave('2026-06-15T15:00:00+12:00');
        $this->assertSame(['late', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['2026-06-15 08:00'], $this->overdueOn('2026-06-15'));
        $this->record('2026-06-15 20:00', 'refused');
        $this->assertSame(['late', 'refused'], $this->rows('2026-06-15')->pluck('state')->all());
    }

    public function test_canonical_hospital_admission_and_early_discharge_are_live_away_evidence(): void
    {
        $this->at('2026-06-15 09:30');
        $admission = $this->hospital('hospital_admission', '2026-06-15T07:00:00+12:00');
        $reason = 'In hospital (since Mon 15 Jun, 7:00 am)';
        $this->assertSame(['away', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['source' => 'hospital', 'id' => $admission->id], $this->rows('2026-06-15')->first()['away']);
        $this->assertSame($reason, $this->medsToday('2026-06-15')[0]['away_reason']);
        $this->assertSame(array_fill(0, 2, 'Metformin — Away · '.$reason), collect($this->calendar(
            '2026-06-18T00:00:00+12:00', '2026-06-19T00:00:00+12:00'))->pluck('title')->all());
        $this->at('2026-06-15 12:30');
        $this->hospital('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id);
        $this->assertSame(['away', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['away', 'upcoming'], array_column($this->medsToday('2026-06-15'), 'status'));
        $this->assertFalse(collect($this->calendar('2026-06-18T00:00:00+12:00', '2026-06-19T00:00:00+12:00'))
            ->contains(fn (array $event): bool => str_contains($event['title'], 'Away')));
    }

    #[DataProvider('pairedHistoricalSources')]
    public function test_paired_historical_absence_before_a_later_closed_stay_ends_away_and_preserves_recorded_priority(string $source): void
    {
        $this->at('2026-06-15 21:30');
        if ($source === 'leave') {
            $later = $this->leave('2026-06-15T15:00:00+12:00');
            $workflow = app(ClientLeaveWorkflow::class);
            $workflow->transition($this->reader, $this->aroha, $later,
                ['action' => 'return', 'version' => $later->version, 'occurred_at' => '2026-06-15T16:00:00+12:00']);
            $earlier = $workflow->create($this->reader, $this->aroha,
                ['starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'status' => 'approved']);
            $historical = $workflow->transition($this->reader, $this->aroha, $earlier,
                ['action' => 'depart', 'version' => $earlier->version, 'occurred_at' => '2026-06-15T07:00:00+12:00',
                    'returned_at' => '2026-06-15T12:00:00+12:00']);
        } else {
            $later = $this->hospital('hospital_admission', '2026-06-15T15:00:00+12:00');
            $this->hospital('hospital_discharge', '2026-06-15T16:00:00+12:00', $later->id);
            $historical = app(ClinicalEventService::class)->record($this->aroha, $this->reader,
                ['event_type' => 'hospital_admission', 'severity' => 'medium', 'description' => 'Closed historical attendance',
                    'occurred_at' => '2026-06-15T07:00:00+12:00', 'hospital_discharged_at' => '2026-06-15T12:00:00+12:00']);
        }
        $this->assertSame(['away', 'late'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['source' => $source, 'id' => $historical->id], $this->rows('2026-06-15')->first()['away']);
        $this->assertSame(['away', 'overdue'], array_column($this->medsToday('2026-06-15'), 'status'));
        $day = CarbonImmutable::parse('2026-06-15', 'Pacific/Auckland');
        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], $day, $day)->get($this->aroha->id);
        $this->assertSame(['source' => $source, 'id' => $historical->id], DoseAwaySources::refAt($periods, CarbonImmutable::parse('2026-06-15T07:00:00+12:00')->utc()));
        $this->assertNull(DoseAwaySources::refAt($periods, CarbonImmutable::parse('2026-06-15T12:00:00+12:00')->utc()));
        $this->assertNull(DoseAwaySources::refAt($periods, CarbonImmutable::now()));
        $this->record('2026-06-15 08:00', 'refused');
        $this->assertSame(['refused', 'late'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['refused', 'overdue'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public static function pairedHistoricalSources(): array
    {
        return ['leave' => ['leave'], 'hospital' => ['hospital']];
    }

    public function test_hospital_does_not_settle_pre_admission_overdue_or_override_a_recorded_refusal(): void
    {
        $this->at('2026-06-15 21:30');
        $this->hospital('hospital_admission', '2026-06-15T15:00:00+12:00');
        $this->assertSame(['late', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['2026-06-15 08:00'], $this->overdueOn('2026-06-15'));
        $this->record('2026-06-15 20:00', 'refused');
        $this->assertSame(['late', 'refused'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->assertSame(['overdue', 'refused'], array_column($this->medsToday('2026-06-15'), 'status'));
    }

    public function test_house_move_preserves_hospital_absence_and_only_current_site_actor_can_record_discharge(): void
    {
        $this->at('2026-06-15 09:30');
        $admission = $this->hospital('hospital_admission', '2026-06-15T07:00:00+12:00');
        $original = (array) DB::table('clinical_events')->where('id', $admission->id)->sole();
        $oldActor = $this->reader;
        $this->grant('clients.update');
        $this->actingAs($this->reader)->putJson('/operations/clients/'.$this->aroha->id, [
            'first_name' => 'Aroha', 'last_name' => 'Ngata', 'status' => 'active', 'site_id' => $this->kowhai->id,
            'service_context_id' => null, 'room_id' => null, 'key_worker_id' => null, 'house_geofence_id' => null,
        ])->assertRedirect();
        $this->aroha->refresh();
        $this->assertSame($this->kowhai->id, $this->aroha->site_id);
        $this->assertSame(['away', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], '2026-06-15', '2026-06-15')->get($this->aroha->id);
        $this->assertSame(['source' => 'hospital', 'id' => $admission->id], DoseAwaySources::refAt($periods, CarbonImmutable::parse('2026-06-15T20:00:00+12:00')->utc()));
        $this->actingAs($oldActor->fresh())->getJson('/clients/'.$this->aroha->id.'/clinical/hospital-admissions')->assertForbidden();
        $this->postJson('/clients/'.$this->aroha->id.'/clinical/events', ['event_type' => 'hospital_discharge', 'severity' => 'medium',
            'description' => 'Returned after move', 'occurred_at' => '2026-06-15T09:00:00+12:00', 'hospital_admission_id' => $admission->id])->assertForbidden();
        $this->assertSame(0, ClinicalEvent::where('hospital_admission_id', $admission->id)->count());
        $this->reader = $this->staff($this->kowhai, ['clients.viewAssigned', 'medications.view', 'calendar.view', 'clinical.events.record']);
        $this->aroha->supportWorkers()->attach($this->reader->id);
        $refs = $this->actingAs($this->reader)->getJson('/clients/'.$this->aroha->id.'/clinical/hospital-admissions')->assertOk()->json('admissions');
        $this->assertCount(1, $refs);
        $this->assertSame(['id', 'occurred_at', 'reported_at'], array_keys($refs[0]));
        $this->assertSame($admission->id, $refs[0]['id']);
        $this->assertFalse(app(ClinicalSiteAccessService::class)->canAccessEvent($this->reader, $admission));
        $this->at('2026-06-15 12:30');
        $discharge = $this->hospital('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id);
        $this->assertSame($this->kowhai->id, $discharge->site_id);
        $this->assertSame('2026-06-15 00:00:00', $discharge->getRawOriginal('hospital_discharged_at'));
        $this->assertSame($original, (array) DB::table('clinical_events')->where('id', $admission->id)->sole());
        $this->assertSame(['away', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $this->getJson('/clients/'.$this->aroha->id.'/clinical/hospital-admissions')->assertOk()->assertExactJson(['admissions' => []]);
    }

    public function test_hospital_source_takes_precedence_then_discharge_restores_ongoing_leave_without_faking_its_return(): void
    {
        $this->at('2026-06-15 09:30');
        $leave = $this->leave('2026-06-15T06:00:00+12:00');
        $admission = $this->hospital('hospital_admission', '2026-06-15T07:00:00+12:00');
        $this->assertSame(['source' => 'hospital', 'id' => $admission->id], $this->rows('2026-06-15')->first()['away']);
        $this->at('2026-06-15 12:30');
        $this->hospital('hospital_discharge', '2026-06-15T12:00:00+12:00', $admission->id);
        $this->assertSame(['hospital', 'leave'], $this->rows('2026-06-15')->map(fn (array $row): string => $row['away']['source'])->all());
        $this->assertNull($leave->fresh()->returned_at);
        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], '2026-06-15', '2026-06-15')->get($this->aroha->id);
        $this->assertSame(['source' => 'leave', 'id' => $leave->id], DoseAwaySources::refAt($periods, CarbonImmutable::parse('2026-06-15T12:00:00+12:00')->utc()));
    }

    public function test_unknown_hospital_history_and_unrelated_or_impossible_discharge_never_fabricate_presence(): void
    {
        $this->at('2026-06-15 09:30');
        ClinicalEvent::factory()->create(['client_id' => $this->aroha->id, 'site_id' => $this->home->id,
            'event_type' => 'hospital_admission', 'occurred_at' => Carbon::parse('2026-06-15T07:00:00+12:00')->utc(), 'hospital_admitted_at' => null]);
        $this->assertSame(['late', 'not_due'], $this->rows('2026-06-15')->pluck('state')->all());
        $admission = $this->hospital('hospital_admission', '2026-06-15T07:00:00+12:00');
        $other = Client::factory()->create(['site_id' => $this->home->id]);
        // Explicit malformed legacy rows must not terminate this person's canonical interval.
        foreach ([[$other->id, $this->home->id, '08:00'], [$this->aroha->id, $this->kowhai->id, '08:00'],
            [$this->aroha->id, $this->home->id, '06:00']] as [$clientId, $siteId, $time]) {
            ClinicalEvent::factory()->create(['client_id' => $clientId, 'site_id' => $siteId, 'event_type' => 'hospital_discharge',
                'hospital_admission_id' => $admission->id, 'occurred_at' => Carbon::parse('2026-06-15 '.$time, 'Pacific/Auckland')->utc()]);
        }
        $this->assertSame(['away', 'away'], $this->rows('2026-06-15')->pluck('state')->all());
        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], '2026-06-15', '2026-06-15')->get($this->aroha->id);
        $this->assertSame(['source' => 'hospital', 'id' => $admission->id], DoseAwaySources::refAt($periods, CarbonImmutable::parse('2026-06-15T20:00:00+12:00')->utc()));
    }

    #[DataProvider('daylightSavingIntervals')]
    public function test_actual_leave_half_open_boundaries_match_projection_and_fallback_through_nz_clock_changes(string $date, string $departure, string $return, int $hours): void
    {
        // Enter this schedule before its doses become due. A noon edit to the
        // existing order correctly retains past slots and awaits verification.
        $this->at(Carbon::parse($date, 'Pacific/Auckland')->subDay()->setTime(12, 0)->toDateTimeString());
        $order = ClientMedication::query()->create([
            'client_id' => $this->aroha->id, 'name' => 'Night dose', 'dosage' => '1 tablet',
            'frequency' => 'Daily', 'dose_times' => ['01:30', '03:30'], 'is_prn' => false,
            'active' => true, 'state' => 'active', 'start_date' => $date,
        ]);
        $this->at($date.' 12:00');
        $leave = $this->leave($departure);
        app(ClientLeaveWorkflow::class)->transition($this->reader, $this->aroha, $leave,
            ['action' => 'return', 'version' => $leave->version, 'occurred_at' => $return]);
        $this->artisan('emar:generate-dose-slots')->assertSuccessful();
        $rows = $this->rows($date)->where('client_medication_id', $order->id)->values();
        $this->assertCount(2, $rows);
        $this->assertSame(['01:30', '03:30'], $rows->pluck('ordered_time')->all());
        $this->assertSame([
            CarbonImmutable::parse($departure)->utc()->toIso8601String(),
            CarbonImmutable::parse($return)->utc()->toIso8601String(),
        ], $rows->pluck('due_at')->all());
        $this->assertSame([false, false], $rows->pluck('order_change_pending')->all());
        $this->assertSame(['away', 'late'], $rows->pluck('state')->all());
        $periods = app(DoseAwaySources::class)->periods([$this->aroha->id], $date, $date)->get($this->aroha->id);
        $this->assertCount(1, $periods);
        $this->assertSame($hours * 3600, $periods[0]['until']->getTimestamp() - $periods[0]['from']->getTimestamp());
        $this->assertSame(['source' => 'leave', 'id' => $leave->id], DoseAwaySources::refAt($periods, CarbonImmutable::parse($departure)->utc()));
        $this->assertNull(DoseAwaySources::refAt($periods, CarbonImmutable::parse($departure)->utc()->subSecond()));
        $this->assertNull(DoseAwaySources::refAt($periods, CarbonImmutable::parse($return)->utc()));
    }

    public static function daylightSavingIntervals(): array
    {
        return [
            'spring' => ['2026-09-27', '2026-09-27T01:30:00+12:00', '2026-09-27T03:30:00+13:00', 1],
            'fall' => ['2027-04-04', '2027-04-04T01:30:00+13:00', '2027-04-04T03:30:00+12:00', 3],
        ];
    }

    public function test_an_away_dose_does_not_hold_a_round_open(): void
    {
        $this->stay('2026-06-15 07:00');
        $round = MedicationRound::query()->create([
            'site_id' => $this->home->id,
            'name' => 'Morning round',
            'round_type' => 'scheduled',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'round_date' => '2026-06-15',
            'status' => 'in_progress',
            'started_by' => $this->reader->id,
        ]);
        $this->at('2026-06-15 08:15');

        $service = app(GuidedRoundService::class);
        $cells = $service->cells($round->fresh(), true);
        $this->assertSame(['away'], array_column($cells, 'status'));
        $item = $service->items($round->fresh(), true)[0];
        $this->assertSame('respite', $item['away_source']);
        // Not owed in the round: left out of the total, the round 100% recorded.
        $progress = $service->progress($round->fresh(), true);
        $this->assertSame([0, 0, 100, 1], [$progress['total'], $progress['pending'], $progress['percent'], $progress['away']]);
        $this->assertTrue($service->canCompleteCanonicalRound($round->fresh()));
    }

    public function test_a_day_past_live_generation_reads_away_from_the_same_records(): void
    {
        $this->stay('2026-06-15 07:00');
        $this->at('2026-06-15 09:30');

        // The 18th is past live generation (today + 2): the order as it is now.
        $titles = collect($this->calendar('2026-06-18T00:00:00+12:00', '2026-06-19T00:00:00+12:00'))->pluck('title')->all();
        $this->assertSame(array_fill(0, 2, 'Metformin — Away · Respite at another house (since Mon 15 Jun, 7:00 am)'), $titles);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function grant(string $permission): void
    {
        $id = Permission::where('key', $permission)->sole()->id;
        $this->reader->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => true]]);
        $this->reader = $this->reader->fresh();
    }

    private function leave(string $departure): ClientLeaveRequest
    {
        $this->grant('clients.update');
        $workflow = app(ClientLeaveWorkflow::class);
        $leave = $workflow->create($this->reader, $this->aroha, [
            'starts_on' => '2026-06-15', 'ends_on' => '2026-06-20', 'status' => 'approved',
        ]);

        return $workflow->transition($this->reader, $this->aroha, $leave,
            ['action' => 'depart', 'version' => $leave->version, 'occurred_at' => $departure]);
    }

    private function hospital(string $type, string $at, ?int $admissionId = null): ClinicalEvent
    {
        $this->grant('clinical.events.record');

        return app(ClinicalEventService::class)->record($this->aroha, $this->reader, [
            'event_type' => $type, 'severity' => 'medium', 'description' => 'Actual hospital attendance',
            'occurred_at' => $at, 'hospital_admission_id' => $admissionId,
        ]);
    }

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    /** A booking whose planned times cover the whole week (never read for Away). */
    private function booking(?int $siteId, string $status): RespiteBooking
    {
        return DB::transaction(fn (): RespiteBooking => RespiteBooking::query()->create([
            'client_id' => $this->aroha->id,
            'location_id' => $siteId,
            'start_at' => Carbon::parse('2026-06-14 00:00', 'Pacific/Auckland')->utc(),
            'end_at' => Carbon::parse('2026-06-21 00:00', 'Pacific/Auckland')->utc(),
            'status' => $status,
        ]));
    }

    /** A respite stay begun at $startNz (as the system writes it at admission). */
    private function stay(string $startNz, string $status = 'active', ?string $bookingStatus = 'in_progress', ?int $siteId = -1): RespiteStay
    {
        $booking = $this->booking($siteId === -1 ? $this->kowhai->id : $siteId, $bookingStatus);

        return DB::transaction(fn (): RespiteStay => RespiteStay::query()->create([
            'booking_id' => $booking->id,
            'client_id' => $this->aroha->id,
            'status' => $status,
            'actual_start' => Carbon::parse($startNz, 'Pacific/Auckland')->utc(),
        ]));
    }

    private function record(string $dueNz, string $status): ClientMedicationAdministration
    {
        return DB::transaction(fn (): ClientMedicationAdministration => ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $this->metformin->id,
            'administered_by' => $this->reader->id,
            'scheduled_for' => Carbon::parse($dueNz, 'Pacific/Auckland')->utc(),
            'administered_at' => Carbon::parse($dueNz, 'Pacific/Auckland')->utc()->addMinutes(5),
            'status' => $status,
            'reason' => $status === 'given' ? null : 'Declined.',
        ]));
    }

    /**
     * The overdue sweep's doses due on an NZ day, as "Y-m-d H:i".
     *
     * @return list<string>
     */
    private function overdueOn(string $nzDate): array
    {
        return app(OverdueDoses::class)->at(now(), [$this->aroha->id])
            ->map(fn (array $dose): string => $dose['due_at']->copy()->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
            ->filter(fn (string $due): bool => str_starts_with($due, $nzDate))
            ->values()
            ->all();
    }

    /** @return Collection<int, ControlRoomAlert> */
    private function openAlerts(): Collection
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->get();
    }

    private function overdueAlertCount(): int
    {
        return ControlRoomAlert::query()
            ->where('source', 'medication')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(context, '$.signal_type_code')) = ?", [MedicationSignalService::TYPE_OVERDUE])
            ->count();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rows(string $nzDate): Collection
    {
        return app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->aroha->id]), $nzDate, $nzDate, CarbonImmutable::now());
    }

    /** @return list<array<string, mixed>> */
    private function medsToday(string $date): array
    {
        return array_values($this->actingAs($this->reader->fresh())
            ->get('/meds/today?date='.$date)
            ->assertOk()
            ->inertiaProps('schedule'));
    }

    /** @return list<array<string, mixed>> */
    private function calendar(string $start, string $end): array
    {
        return collect($this->actingAs($this->reader->fresh())
            ->getJson(route('client.calendar.events', ['client' => $this->aroha, 'start' => $start, 'end' => $end], false))
            ->assertOk()
            ->json())
            ->filter(fn (array $event): bool => ($event['extendedProps']['type'] ?? null) === 'medication')
            ->sortBy('start')
            ->values()
            ->all();
    }

    /** @param list<string> $permissions */
    private function staff(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => '2026-01-01',
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }
}
