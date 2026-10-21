<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\ShiftMedicationSnapshotService;
use App\Services\EnhancedMarService;
use App\Services\ShiftHandoverService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * eMAR audit Lane C5 (EA-019): clock-out counts only the doses the worker
 * leaving now still owes — due by now (or late), inside the shift, staff's
 * to give, for a person and medicine they may record — from the same
 * projection as Meds today — and the handover snapshot lists only doses
 * staff give (EA-105). Monday 15 June 2026, NZST.
 */
class C5ClockOutDosesTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $aroha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-14 12:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Clock-out', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = $this->person('Aroha');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** EA-019 case 1: the 08:00 dose at the end of a sleepover belongs to the day shift. */
    public function test_a_sleepover_worker_leaving_at_seven_fifty_five_is_not_blocked_by_the_eight_oclock_dose(): void
    {
        $this->order($this->aroha, 'Thyroxine', ['08:00']);
        [$worker, $shift] = $this->onShift($this->aroha, '2026-06-14 22:00', '2026-06-15 08:00');
        $session = $this->openSession($worker, $shift, '2026-06-14 22:00');

        $this->at('2026-06-15 07:55');
        $this->assertSame(0, $this->unsigned($session));
    }

    /** EA-019 case 2: sent home sick at 11:30, blocked only by the dose already late. */
    public function test_a_worker_sent_home_early_is_blocked_only_by_doses_already_due(): void
    {
        $iron = $this->order($this->aroha, 'Iron', ['09:00']);
        $this->order($this->aroha, 'Calcium', ['12:00']);
        $this->order($this->aroha, 'Vitamin D', ['14:00']);
        [$worker, $shift] = $this->onShift($this->aroha, '2026-06-15 07:00', '2026-06-15 15:00');
        $session = $this->openSession($worker, $shift, '2026-06-15 07:00');

        $this->at('2026-06-15 11:30');
        $this->assertSame(1, $this->unsigned($session));

        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $iron->id, 'administered_by' => $worker->id,
            'scheduled_for' => $this->nz('2026-06-15 09:00'), 'administered_at' => $this->nz('2026-06-15 11:25'), 'status' => 'given',
        ]);
        $this->assertSame(0, $this->unsigned($session));
    }

    /** Only doses that are staff's to give, for a medicine the worker may record. */
    public function test_doses_waiting_for_the_check_and_controlled_doses_the_worker_cant_record_dont_count(): void
    {
        $this->order($this->aroha, 'Metformin', ['07:30']);
        $waiting = $this->order($this->aroha, 'Paracetamol', ['08:00']);
        $this->order($this->aroha, 'Morphine', ['08:30'], ['controlled_drug' => true]);
        $this->at('2026-06-15 06:00');
        $waiting->update(['dosage' => '2 tablets']); // waits for the order check
        [$worker, $shift] = $this->onShift($this->aroha, '2026-06-15 07:00', '2026-06-15 15:00', deny: ['medications.controlled.view', 'medications.controlled.record']);
        $session = $this->openSession($worker, $shift, '2026-06-15 07:00');

        $this->at('2026-06-15 10:00');
        $this->assertSame(1, $this->unsigned($session), 'Only Metformin: not the dose waiting for its check, not the controlled one.');
    }

    /** A worker who can't open the person's medicines can't record them, so isn't blocked by them. */
    public function test_a_worker_who_cannot_open_the_persons_medicines_is_not_blocked_by_them(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);
        [$worker, $shift] = $this->onShift($this->aroha, '2026-06-15 07:00', '2026-06-15 15:00', deny: ['medications.view']);
        $session = $this->openSession($worker, $shift, '2026-06-15 07:00');

        $this->at('2026-06-15 10:00');
        $this->assertSame(0, $this->unsigned($session));
    }

    /** EA-019: a forced clock-out hands over the outstanding doses, whatever the wizard's tick says. */
    public function test_a_forced_clock_out_with_doses_outstanding_hands_them_over(): void
    {
        $this->order($this->aroha, 'Metformin', ['08:00']);
        [$worker, $shift] = $this->onShift($this->aroha, '2026-06-15 07:00', '2026-06-15 15:00', grant: ['shifts.manageAny']);
        $session = $this->openSession($worker, $shift, '2026-06-15 07:00');

        $this->at('2026-06-15 11:30');
        app(AttendanceService::class)->clockOut($worker, $session, [
            'force' => true,
            'override_reason' => 'Sent home unwell; lead told.',
            'handover' => ['meds_completed' => true, 'shift_rating' => 'good', 'handover_notes' => '', 'follow_up_needed' => false],
        ]);

        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole();
        $this->assertSame(ShiftHandoverService::OUTSTANDING_MEDICATION_DUE_LABEL, $handover->medications_due[0]['label'] ?? null);
    }

    /** EA-105: the handover's "Medications due" never lists a dose the person manages themselves. */
    public function test_the_handover_snapshot_leaves_out_a_dose_the_person_manages_themselves(): void
    {
        $this->order($this->aroha, 'Metformin', ['14:00']);
        $ventolin = $this->order($this->aroha, 'Ventolin', ['14:00']);
        MedicationSelfAdminAssessment::query()->create([
            'client_id' => $this->aroha->id, 'status' => 'completed', 'outcome' => 'independent', 'assessment_date' => '2026-06-14',
            'cognitive_capacity' => 5, 'physical_dexterity' => 5, 'vision_ability' => 5, 'swallowing_ability' => 5, 'understanding_score' => 5,
            'willing_to_self_admin' => true, 'wishes_to_self_administer' => true,
            'med_scope' => [['med_id' => $ventolin->id, 'scope' => 'self_managed']],
        ]);
        [, $shift] = $this->onShift($this->aroha, '2026-06-15 07:00', '2026-06-15 15:00');

        $this->at('2026-06-15 14:50');
        $snapshot = app(ShiftMedicationSnapshotService::class)->forShift($shift->fresh(), true);
        $this->assertSame(['Metformin'], array_column($snapshot['due'], 'name'));
        $this->assertSame(1, $snapshot['counts']['due']);

        // The MAR row says so, and offers no recording.
        $row = collect(app(EnhancedMarService::class)->build($this->aroha->fresh(), Carbon::parse('2026-06-15'), null, null, true)['scheduled'])
            ->firstWhere('medication.name', 'Ventolin');
        $this->assertSame('self_managed', $row['schedule_state']);
        $this->assertSame('Self-managed', $row['schedule_state_label']['label']);
        $this->assertFalse($row['can_record']);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function unsigned(HrAttendanceSession $session): int
    {
        $blocker = collect(app(AttendanceService::class)->getEndOfShiftBlockers($session->fresh()))->firstWhere('key', 'meds_unsigned');

        return (int) ($blocker['count'] ?? 0);
    }

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }

    private function person(string $name): Client
    {
        return Client::factory()->create(['first_name' => $name, 'last_name' => 'Ngata', 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'status' => 'active']);
    }

    /** Entered the day before, so every dose on the 15th is owed. */
    private function order(Client $client, string $name, array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $client->id, 'name' => $name, 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => $doseTimes, 'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-06-01',
            'approval_status' => 'verified',
        ], $overrides));
    }

    /**
     * @param  list<string>  $deny
     * @param  list<string>  $grant
     * @return array{0: User, 1: Shift}
     */
    private function onShift(?Client $client, string $startsNz, string $endsNz, array $deny = [], array $grant = []): array
    {
        $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
        $worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $deny)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])->all()
            + Permission::query()->whereIn('key', $grant)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all(),
        );
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true]);
        if ($client) {
            $client->supportWorkers()->syncWithoutDetaching([$worker->id]);
        }
        $shift = Shift::factory()->create([
            'client_id' => $client?->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $worker->id, 'starts_at' => $this->nz($startsNz), 'ends_at' => $this->nz($endsNz),
            'actual_starts_at' => $this->nz($startsNz), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
        Cache::flush();

        return [$worker->fresh(), $shift];
    }

    private function openSession(User $worker, Shift $shift, string $clockInNz): HrAttendanceSession
    {
        return HrAttendanceSession::query()->create([
            'tenant_id' => null, 'user_id' => $worker->id, 'site_id' => $this->site->id, 'shift_id' => $shift->id,
            'clock_in_at' => $this->nz($clockInNz), 'status' => 'open', 'source' => 'manual', 'created_by' => $worker->id,
        ]);
    }
}
