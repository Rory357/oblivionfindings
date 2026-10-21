<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Followups\CancelledShiftDoseReview;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\ShiftCancellationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * eMAR audit Lane C5 (EA-070, EA-071, EA-165, EA-167): cancelling a shift
 * that already has doses recorded on it never rewrites those dose records.
 * Each recorded dose gets ONE lead follow-up to review it, due by the end of
 * the next shift (the dose-review rule), and a P09 event — all in the
 * cancellation's own transaction, through ShiftCancellationService.
 * Monday 15 June 2026, NZST.
 */
class C5ShiftCancellationTest extends TestCase
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
        $this->at('2026-06-14 12:00');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Cancellation', 'type' => 'residential', 'is_active' => true, 'site_id' => $this->site->id]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->admin = $this->staff('admin');
        $this->worker = $this->staff('support_worker');
        $this->aroha->supportWorkers()->syncWithoutDetaching([$this->worker->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cancelling_a_shift_leaves_its_dose_records_alone_and_asks_a_lead_to_review_each(): void
    {
        $metformin = $this->order('Metformin', ['08:00']);
        $morphine = $this->order('Morphine', ['08:00'], ['controlled_drug' => true]);
        $this->at('2026-06-15 07:00');
        $shift = $this->shift($this->worker, '2026-06-15 07:00', '2026-06-15 15:00', 'in_progress');
        $next = $this->shift($this->staff('support_worker'), '2026-06-15 15:00', '2026-06-15 23:00', 'scheduled');

        $this->at('2026-06-15 08:05');
        $given = $this->record($metformin, $shift);
        // Already flagged by someone else for its own reason: kept as it was.
        $flagged = $this->record($morphine, $shift, [
            'review_required' => true, 'review_reason' => 'Second person not confirmed.',
            'review_flagged_at' => now(), 'review_flagged_by' => $this->admin->id,
        ]);
        $before = [$given->id => $given->fresh()->getRawOriginal(), $flagged->id => $flagged->fresh()->getRawOriginal()];

        $this->at('2026-06-15 10:00');
        $this->actingAs($this->admin->fresh())->patch('/operations/shifts/'.$shift->id.'/cancel')
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $shift->fresh()->status);

        // EA-071/167: the clinical records are not touched — notes, flags and who flagged them.
        foreach ($before as $id => $raw) {
            $this->assertSame($raw, ClientMedicationAdministration::query()->findOrFail($id)->getRawOriginal());
        }

        // EA-165/070: one lead follow-up per recorded dose, due by the end of the next shift.
        $rows = MedicationFollowup::query()->where('type', CancelledShiftDoseReview::TYPE)->orderBy('administration_id')->get();
        $this->assertSame([$given->id, $flagged->id], $rows->pluck('administration_id')->map(fn ($id) => (int) $id)->all());
        $this->assertContains(CancelledShiftDoseReview::TYPE, MedicationFollowupService::LEAD_TYPES);
        foreach ($rows as $row) {
            $this->assertSame('shift-cancelled:'.$shift->id.'-'.$row->administration_id, $row->source_key);
            $this->assertNull($row->owner_id);
            $this->assertSame(ShiftCancellationService::MEDICATION_REVIEW_REASON, $row->context['reason']);
            $this->assertSame($shift->id, (int) $row->context['shift_id']);
            $this->assertSame($next->ends_at->toIso8601String(), $row->due_at->toIso8601String());
        }

        // P09: one review event per dose, controlled flagged as controlled.
        $events = MedicationEvent::query()->where('kind', 'dose.review_flagged')->orderBy('subject_id')->get();
        $this->assertSame([(string) $given->id, (string) $flagged->id], $events->pluck('subject_id')->all());
        $this->assertSame([false, true], $events->pluck('controlled')->all());
        $this->assertSame('shift_cancelled', $events->first()->facts['reason']);

        // The cascade still reports the dose records it found.
        $audit = AuditLog::query()->where('action', 'shift.cancel.cascade')->sole();
        $this->assertSame([$given->id, $flagged->id], data_get($audit->meta, 'impacts.medication_administrations.ids'));

        // A house lead signs one off.
        $lead = $this->lead();
        $this->actingAs($lead)->postJson('/medication-followups/'.$rows[0]->id.'/transition', [
            'action' => 'signoff', 'outcome' => 'Checked with the worker; the 08:00 Metformin was given.',
            'revision' => $rows[0]->revision, 'request_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertNotNull($rows[0]->fresh()->completed_at);
        $this->assertSame($before[$given->id], $given->fresh()->getRawOriginal());
    }

    /** Main, 20 Oct: an idempotent retry never duplicates the reviews (one source key per dose). */
    public function test_cancelling_the_same_shift_twice_keeps_one_review_per_dose(): void
    {
        $order = $this->order('Metformin', ['08:00']);
        $this->at('2026-06-15 07:00');
        $shift = $this->shift($this->worker, '2026-06-15 07:00', '2026-06-15 15:00', 'in_progress');
        $this->at('2026-06-15 08:05');
        $dose = $this->record($order, $shift);

        $this->at('2026-06-15 10:00');
        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->admin->fresh())->patch('/operations/shifts/'.$shift->id.'/cancel')
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        // And a retry of the review step itself (e.g. after a reopen and cancel).
        DB::transaction(fn () => app(CancelledShiftDoseReview::class)->raise($shift->fresh(), $this->admin));

        $this->assertSame(['shift-cancelled:'.$shift->id.'-'.$dose->id],
            MedicationFollowup::query()->where('type', CancelledShiftDoseReview::TYPE)->pluck('source_key')->all());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'dose.review_flagged')->count());
    }

    /** Main, 20 Oct: a future shift with nothing recorded on it raises nothing. */
    public function test_cancelling_a_future_shift_with_no_recorded_doses_raises_nothing(): void
    {
        $order = $this->order('Metformin', ['08:00']);
        $this->at('2026-06-15 06:00');
        $shift = $this->shift($this->worker, '2026-06-15 07:00', '2026-06-15 15:00', 'scheduled');
        // A dose record on another shift is not this shift's.
        $other = $this->shift($this->staff('support_worker'), '2026-06-14 22:00', '2026-06-15 07:00', 'in_progress');
        $this->at('2026-06-15 06:30');
        $this->record($order, $other, ['scheduled_for' => $this->nz('2026-06-15 08:00')]);

        $this->actingAs($this->admin->fresh())->patch('/operations/shifts/'.$shift->id.'/cancel')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, MedicationFollowup::query()->where('type', CancelledShiftDoseReview::TYPE)->count());
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'dose.review_flagged')->count());
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }

    private function order(string $name, array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->aroha->id, 'name' => $name, 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => $doseTimes, 'is_prn' => false, 'active' => true, 'state' => 'active', 'start_date' => '2026-06-01',
            'approval_status' => 'verified',
        ], $overrides));
    }

    private function record(ClientMedication $order, Shift $shift, array $overrides = []): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create(array_merge([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id, 'shift_id' => $shift->id,
            'administered_by' => $shift->user_id, 'scheduled_for' => $this->nz('2026-06-15 08:00'),
            'administered_at' => now(), 'status' => 'given', 'notes' => 'Taken with breakfast.',
        ], $overrides));
    }

    private function shift(User $worker, string $startsNz, string $endsNz, string $status): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->aroha->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $worker->id, 'starts_at' => $this->nz($startsNz), 'ends_at' => $this->nz($endsNz),
            'actual_starts_at' => $status === 'in_progress' ? $this->nz($startsNz) : null, 'actual_ends_at' => null,
            'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    private function lead(): User
    {
        $lead = $this->staff('team_lead');
        $lead->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', [
            'medications.view', 'medications.administer.record', 'medications.followups.manage', 'clients.viewAny',
            'medications.controlled.view', 'medications.controlled.record',
        ])->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all());

        return $lead->fresh();
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
