<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\RespiteBooking;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftSeries;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Followups\CancelledShiftDoseReview;
use App\Services\Respite\RespiteShiftSync;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * eMAR audit Lane C5b (Main, 21 Oct): the two other places a shift is
 * cancelled — a recurring series' "cancel future occurrences" and a
 * respite booking cancelled or recorded as a no-show — give each dose
 * recorded on the cancelled shift the same single lead review as
 * ShiftCancellationService (CancelledShiftDoseReview, one source key per
 * dose). "From today" for a series is today in New Zealand, not the UTC
 * day. Monday 15 June 2026, NZST (UTC+12).
 */
class C5bCancellationBypassTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $aroha;

    private User $admin;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->at('2026-06-01 09:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Bypass', 'type' => 'planned_respite', 'is_active' => true]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->admin = $this->staff('admin');
        $this->worker = $this->staff('support_worker');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** At 14:00 NZ the UTC day began at 12:00 NZ: this morning's occurrences are still "from today". */
    public function test_cancelling_a_series_from_today_uses_the_new_zealand_day(): void
    {
        $series = $this->series();
        $yesterdayLate = $this->seriesShift($series, '2026-06-14 23:30', '2026-06-15 00:15');
        $justAfterMidnight = $this->seriesShift($series, '2026-06-15 00:30', '2026-06-15 01:30');
        $thisMorning = $this->seriesShift($series, '2026-06-15 09:00', '2026-06-15 10:00');
        $tomorrow = $this->seriesShift($series, '2026-06-16 00:30', '2026-06-16 01:30');

        $this->at('2026-06-15 14:00');
        $this->actingAs($this->admin->fresh())->patch(route('operations.shifts.series.cancel_future', $series))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('scheduled', $yesterdayLate->fresh()->status, 'Yesterday (NZ) is not "from today".');
        $this->assertSame('cancelled', $justAfterMidnight->fresh()->status);
        $this->assertSame('cancelled', $thisMorning->fresh()->status);
        $this->assertSame('cancelled', $tomorrow->fresh()->status);
    }

    /** A dose recorded on a series occurrence that is then cancelled gets exactly one lead review. */
    public function test_a_dose_recorded_on_a_cancelled_series_occurrence_gets_one_review(): void
    {
        $order = $this->order();
        $series = $this->series();
        $withDose = $this->seriesShift($series, '2026-06-15 07:00', '2026-06-15 15:00', 'in_progress');
        $empty = $this->seriesShift($series, '2026-06-16 07:00', '2026-06-16 15:00');
        $this->at('2026-06-15 08:05');
        $dose = $this->record($order, $withDose);

        $this->at('2026-06-15 14:00');
        $this->actingAs($this->admin->fresh())->patch(route('operations.shifts.series.cancel_future', $series))
            ->assertRedirect()->assertSessionHasNoErrors();
        // A repeat finds nothing left to cancel and raises nothing more.
        $this->actingAs($this->admin->fresh())->patch(route('operations.shifts.series.cancel_future', $series))->assertRedirect();

        $this->assertSame('cancelled', $empty->fresh()->status);
        $this->assertSame(['shift-cancelled:'.$withDose->id.'-'.$dose->id], $this->reviewKeys(), 'One review, for the recorded dose only.');
        $this->assertSame([(string) $dose->id], MedicationEvent::query()->where('kind', 'dose.review_flagged')->pluck('subject_id')->all());
    }

    /** A respite booking cancelled while its shift has a recorded dose: one review, never two. */
    public function test_a_dose_on_a_cancelled_respite_booking_shift_gets_one_review(): void
    {
        $order = $this->order();
        [$booking, $shift] = $this->respite('2026-06-15 07:00', '2026-06-17 10:00');
        $this->at('2026-06-15 08:05');
        $dose = $this->record($order, $shift);

        $this->at('2026-06-15 14:00');
        $booking->update(['status' => 'cancelled', 'cancellation_reason' => 'Family took Aroha home.']);
        app(RespiteShiftSync::class)->syncBooking($booking->fresh());
        app(RespiteShiftSync::class)->syncBooking($booking->fresh());

        $this->assertSame('cancelled', $shift->fresh()->status);
        $this->assertSame(['shift-cancelled:'.$shift->id.'-'.$dose->id], $this->reviewKeys());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'dose.review_flagged')->count());
    }

    /** A no-show cancels the shift too; with a dose on it that dose is reviewed, without one nothing is raised. */
    public function test_a_respite_no_show_reviews_recorded_doses_and_raises_nothing_otherwise(): void
    {
        $order = $this->order();
        [$withDose, $shift] = $this->respite('2026-06-15 07:00', '2026-06-16 10:00');
        [$empty, $emptyShift] = $this->respite('2026-06-18 07:00', '2026-06-19 10:00');
        $this->at('2026-06-15 08:05');
        $dose = $this->record($order, $shift);

        $this->at('2026-06-15 14:00');
        foreach ([$withDose, $empty] as $booking) {
            $booking->update(['status' => 'no_show']);
            app(RespiteShiftSync::class)->syncBooking($booking->fresh());
        }

        $this->assertSame('cancelled', $emptyShift->fresh()->status);
        $this->assertSame(['shift-cancelled:'.$shift->id.'-'.$dose->id], $this->reviewKeys());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private function reviewKeys(): array
    {
        return MedicationFollowup::query()->where('type', CancelledShiftDoseReview::TYPE)->orderBy('id')->pluck('source_key')->all();
    }

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }

    private function order(): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->aroha->id, 'name' => 'Metformin', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['08:00'], 'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-06-01',
            'approval_status' => 'verified',
        ]);
    }

    private function record(ClientMedication $order, Shift $shift): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id, 'shift_id' => $shift->id,
            'administered_by' => $this->worker->id, 'scheduled_for' => $this->nz('2026-06-15 08:00'),
            'administered_at' => now(), 'status' => 'given',
        ]);
    }

    private function series(): ShiftSeries
    {
        return ShiftSeries::query()->create([
            'client_id' => $this->aroha->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30',
            'timezone' => 'Pacific/Auckland', 'by_weekday' => ['mon', 'tue'], 'starts_time' => '07:00', 'ends_time' => '15:00',
            'status' => 'scheduled', 'created_by' => $this->admin->id,
        ]);
    }

    private function seriesShift(ShiftSeries $series, string $startsNz, string $endsNz, string $status = 'scheduled'): Shift
    {
        return Shift::factory()->create([
            'shift_series_id' => $series->id, 'client_id' => $this->aroha->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'user_id' => $this->worker->id,
            'starts_at' => $this->nz($startsNz), 'ends_at' => $this->nz($endsNz),
            'actual_starts_at' => $status === 'in_progress' ? $this->nz($startsNz) : null, 'actual_ends_at' => null,
            'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    /** @return array{0: RespiteBooking, 1: Shift} */
    private function respite(string $startsNz, string $endsNz): array
    {
        $booking = RespiteBooking::factory()->create([
            'client_id' => $this->aroha->id, 'start_at' => $this->nz($startsNz), 'end_at' => $this->nz($endsNz), 'status' => 'confirmed',
        ]);
        $shift = Shift::factory()->create([
            'client_id' => $this->aroha->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'respite_booking_id' => $booking->id, 'user_id' => $this->worker->id,
            'starts_at' => $this->nz($startsNz), 'ends_at' => $this->nz($endsNz),
            'actual_starts_at' => $this->nz($startsNz), 'actual_ends_at' => null, 'status' => 'in_progress',
            'created_by' => $this->admin->id,
        ]);

        return [$booking, $shift];
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true]);

        return $user;
    }
}
