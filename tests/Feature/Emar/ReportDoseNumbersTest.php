<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * C6(h): the eMAR reports page and /compliance count scheduled doses from the
 * dose-slot projection (P09's numbers) by the NZ day each was due: due once
 * its window has ended, then given, refused, withheld, recorded missed or not
 * recorded; "Not applicable" (null) when nothing was due; "Not available
 * before …" for days before the projection.
 *
 * Monday 15 June 2026 (NZST, UTC+12): a 07:00 dose is 19:00 UTC on Sunday.
 */
class ReportDoseNumbersTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $aroha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->aroha = Client::factory()->create(['first_name' => 'Aroha', 'last_name' => 'Ngata', 'site_id' => $this->site->id, 'status' => 'active']);

        $this->at('2026-06-15 00:00');
        $metformin = $this->order('Metformin', ['07:00']);
        $iron = $this->order('Iron', ['07:00']);
        $this->order('Calcium', ['07:00']);                      // not recorded
        $this->order('Melatonin', ['21:00']);                    // not yet due
        $this->at('2026-06-15 07:05');
        $this->record($metformin, 'given');
        $this->record($iron, 'refused');
        $this->at('2026-06-15 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_reports_page_counts_scheduled_doses_by_their_nz_day(): void
    {
        $page = $this->actingAs($this->reader())
            ->get(route('emar.reports', ['date_from' => '2026-06-15', 'date_to' => '2026-06-15']))
            ->assertOk();

        $this->assertSame(
            ['total' => 3, 'given' => 1, 'refused' => 1, 'withheld' => 0, 'missed' => 0, 'not_recorded' => 1, 'compliance_rate' => 33.3],
            array_intersect_key($page->inertiaProps('adminSummary'), array_flip(['total', 'given', 'refused', 'withheld', 'missed', 'not_recorded', 'compliance_rate'])),
        );
        // One NZ day — Monday — though the doses were due on Sunday in UTC.
        $this->assertSame(['Jun 15'], collect($page->inertiaProps('dailyAdmin'))->pluck('date')->all());
        $this->assertSame(
            [['client_name' => 'Aroha Ngata', 'total' => 3, 'not_recorded' => 1, 'compliance' => 33.3]],
            collect($page->inertiaProps('clientBreakdown'))->map(fn (array $row): array => Arr::only($row, ['client_name', 'total', 'not_recorded', 'compliance']))->all(),
        );
        $this->assertNull($page->inertiaProps('dose_notice'));
    }

    public function test_a_day_with_nothing_due_is_not_applicable(): void
    {
        $page = $this->actingAs($this->reader())
            ->get(route('emar.reports', ['date_from' => '2026-06-16', 'date_to' => '2026-06-16']))
            ->assertOk();

        $this->assertSame(0, $page->inertiaProps('adminSummary.total'));
        $this->assertNull($page->inertiaProps('adminSummary.compliance_rate'));
    }

    public function test_a_period_before_the_dose_record_says_so(): void
    {
        $page = $this->actingAs($this->reader())
            ->get(route('emar.reports', ['date_from' => '2026-06-01', 'date_to' => '2026-06-15']))
            ->assertOk();

        $this->assertSame('Not available before 15 June 2026', $page->inertiaProps('dose_notice'));
        $this->assertSame(3, $page->inertiaProps('adminSummary.total'));
    }

    public function test_compliance_mar_exceptions_count_doses_not_given_or_not_recorded_today(): void
    {
        $viewer = $this->reader(['compliance.view']);
        $props = $this->actingAs($viewer)->get('/compliance')->assertOk()->inertiaProps();

        $mar = collect($props['kpis'])->firstWhere('key', 'mar');
        // Iron refused + Calcium not recorded; Metformin given; Melatonin not due.
        $this->assertSame(2, $mar['value']);
        $this->assertSame(2, (int) end($mar['spark']));
        $this->assertSame(
            ['date' => '2026-06-15', 'given' => 1, 'missed' => 0, 'refused' => 1, 'withheld' => 0, 'not_recorded' => 1],
            collect($props['charts']['marTrend'])->firstWhere('date', '2026-06-15'),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function at(string $nz): void
    {
        Carbon::setTestNow(Carbon::parse($nz, 'Pacific/Auckland')->utc());
    }

    /** @param list<string> $doseTimes */
    private function order(string $name, array $doseTimes): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $this->aroha->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-06-01',
        ]);
    }

    private function record(ClientMedication $order, string $status): void
    {
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->aroha->id,
            'client_medication_id' => $order->id,
            'administered_by' => User::factory()->create(['approved_at' => now()])->id,
            'scheduled_for' => Carbon::parse('2026-06-15 07:00', 'Pacific/Auckland')->utc(),
            'administered_at' => now(),
            'status' => $status,
            'reason' => $status === 'given' ? null : 'Declined.',
        ]);
    }

    /**
     * A reports reader across all Sites (admin role) with the given extra
     * permissions.
     *
     * @param  list<string>  $extra
     */
    private function reader(array $extra = []): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', ['medications.reports.export', 'medications.view', ...$extra])->pluck('id')
                ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])
                ->all(),
        );

        return $user->fresh();
    }
}
