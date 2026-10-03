<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C6(g): the audit's omissions come from the dose-slot projection — every
 * dose in the period whose window ended with nothing recorded, the same
 * doses Meds today and the overdue alerts call overdue — over the whole
 * period (no 31-day clamp), for orders since ceased too; never a dose still
 * in its window, waiting for the order check, or recorded (missed is a
 * record). Controlled medicine names require controlled-medicine access;
 * the concealed dose still counts for other readers.
 *
 * "Now" is Monday 15 June 2026, 08:30 NZST.
 */
class AuditOmissionsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_omissions_cover_the_whole_period_and_ceased_orders(): void
    {
        // Entered 40 days ago (Wed 6 May): its first days' doses went
        // unrecorded, then it was ceased. The old omission check looked back
        // at most 31 days, and only at active orders.
        $this->at('2026-05-06 00:00');
        $ceased = $this->order('Amoxicillin', ['09:00']);
        $this->at('2026-05-08 12:00');
        $ceased->update([
            'state' => 'ceased',
            'active' => false,
            'ceased_at' => now(),
            'ceased_reason' => 'Course finished.',
            'ceased_by' => User::factory()->create(['approved_at' => now()])->id,
        ]);

        $this->at('2026-06-15 08:30');
        $omissions = $this->omissions($this->reader(), ['date_from' => '2026-05-01', 'date_to' => '2026-06-15']);

        // 6 and 7 May; the 8th's 09:00 dose fell before the order was ceased at
        // noon, so it was owed too.
        $this->assertSame(
            ['Amoxicillin 2026-05-06 09:00', 'Amoxicillin 2026-05-07 09:00', 'Amoxicillin 2026-05-08 09:00'],
            $omissions,
        );
    }

    public function test_only_doses_whose_window_has_ended_unrecorded_are_omissions(): void
    {
        $this->at('2026-06-15 00:00');
        $this->order('Metformin', ['07:00']);                 // window ended 08:00: an omission
        $this->order('Iron', ['08:00']);                      // in its window at 08:30: not yet
        $given = $this->order('Vitamin D', ['07:15']);         // given
        $missed = $this->order('Calcium', ['06:30']);          // recorded as missed: a record
        $this->order('Paracetamol', ['07:30'], ['approval_status' => 'pending_verification']); // never checked
        $this->at('2026-06-15 06:45');
        $this->record($given, '2026-06-15 07:15', 'given');
        $this->record($missed, '2026-06-15 06:30', 'missed');

        $this->at('2026-06-15 08:30');

        $this->assertSame(['Metformin 2026-06-15 07:00'], $this->omissions($this->reader()));
    }

    public function test_controlled_omission_names_require_controlled_access_without_changing_counts(): void
    {
        $this->at('2026-06-15 00:00');
        $this->order('Metformin', ['07:00']);
        $this->order('Morphine', ['07:00'], ['controlled_drug' => true]);
        $this->at('2026-06-15 08:30');

        $this->assertSame(['Metformin 2026-06-15 07:00', 'Morphine 2026-06-15 07:00'], $this->omissions($this->reader(controlled: true)));
        $this->assertSame(['Controlled medicine 2026-06-15 07:00', 'Metformin 2026-06-15 07:00'], $this->omissions($this->reader(controlled: false)));
    }

    public function test_pending_amendment_keeps_the_checked_order_dose_in_omission_evidence(): void
    {
        $this->at('2026-06-15 00:00');
        $order = $this->order('Checked medicine', ['07:00']);
        $actor = $this->reader();
        $checked = MedicationOrderVersion::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id, 'version_number' => 1,
            'name' => 'Checked medicine', 'dosage' => '1 tablet', 'route' => 'oral',
            'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
        MedicationOrderRevision::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id,
            'medication_order_version_id' => $checked->id, 'base_version' => 1,
            'status' => 'checked', 'checked_at' => now(), 'checked_by' => $actor->id, 'entered_by' => $actor->id,
        ]);
        $this->at('2026-06-15 06:00');
        $proposal = MedicationOrderVersion::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id, 'version_number' => 2,
            'name' => 'Proposed medicine name', 'dosage' => '99 tablets', 'route' => 'oral',
            'changed_by' => $actor->id, 'changed_at' => now(),
        ]);
        MedicationOrderRevision::query()->create([
            'client_id' => $this->aroha->id, 'client_medication_id' => $order->id,
            'medication_order_version_id' => $proposal->id, 'base_version' => 1,
            'status' => 'pending', 'entered_by' => $actor->id,
        ]);
        $this->at('2026-06-15 08:30');
        $this->actingAs($actor)->get('/emar/reports?view=audit&sub=gaps&period=today')->assertOk()
            ->assertInertia(fn ($page) => $page->has('page.data', 1)
                ->where('page.data.0.medicine', 'Checked medicine')->where('page.data.0.dose', '1 tablet')
                ->where('page.data.0.version_reference', 'order-version:'.$checked->id)
                ->where('page.data.0.status', 'not_recorded'));
    }

    public function test_a_period_before_the_dose_record_says_so(): void
    {
        $this->at('2026-06-15 00:00');
        $this->order('Metformin', ['07:00']);
        $this->at('2026-06-15 08:30');

        $this->actingAs($this->reader())
            ->get('/emar/reports?view=audit&sub=gaps&date_from=2026-06-01&date_to=2026-06-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.notice', 'Not available before 15 June 2026'));
        $this->actingAs($this->reader())
            ->get('/emar/reports?view=audit&sub=gaps&date_from=2026-06-15&date_to=2026-06-15')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.notice', null));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string> "Name Y-m-d H:i" of each omission, sorted
     */
    private function omissions(User $reader, array $query = []): array
    {
        $events = $this->actingAs($reader)
            ->get('/emar/reports?'.http_build_query($query + ['view' => 'audit', 'sub' => 'gaps', 'period' => isset($query['date_from']) ? 'custom' : 'today']))
            ->assertOk()
            ->inertiaProps('page.data');

        return collect($events)
            ->where('status', 'not_recorded')
            ->map(fn (array $event): string => $event['medicine'].' '
                .Carbon::parse($event['due_at'])->timezone('Pacific/Auckland')->format('Y-m-d H:i'))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $doseTimes
     * @param  array<string, mixed>  $overrides
     */
    private function order(string $name, array $doseTimes, array $overrides = []): ClientMedication
    {
        return ClientMedication::query()->create(array_merge([
            'client_id' => $this->aroha->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-05-01',
        ], $overrides));
    }

    private function record(ClientMedication $order, string $dueNz, string $status): void
    {
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => User::factory()->create(['approved_at' => now()])->id,
            'scheduled_for' => Carbon::parse($dueNz, 'Pacific/Auckland')->utc(),
            'administered_at' => now(),
            'status' => $status,
            'reason' => $status === 'given' ? null : 'Recorded for the test.',
        ]);
    }

    /** An auditor across all Sites, with or without controlled-medicine access. */
    private function reader(bool $controlled = true): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $keys = ['medications.audit.view' => true, 'medications.controlled.view' => $controlled];
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', array_keys($keys))->get()
                ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $keys[$permission->key]]])
                ->all(),
        );

        return $user->fresh();
    }
}
