<?php

namespace Tests\Feature\Billing;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ServiceAgreement;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetClientAllocation;
use App\Models\User;
use App\Services\Operations\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BillingService::generateFromTimesheet now fans out across the per-client
 * allocation rows on a timesheet. Legacy timesheets without explicit
 * allocation rows synthesise a single allocation matching the timesheet's
 * primary client, so the legacy 1:1 path continues to produce one
 * BillingEntry per timesheet.
 */
class GenerateFromTimesheetAllocationsTest extends TestCase
{
    use RefreshDatabase;

    private BillingService $service;

    private Site $site;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-05-12 09:00:00'));

        $this->service = app(BillingService::class);
        $this->site = Site::factory()->create(['name' => 'Kauri House']);
        $this->staff = User::factory()->create(['organization_id' => 1]);
    }

    public function test_legacy_timesheet_with_no_allocation_rows_produces_one_entry_via_synthesised_allocation(): void
    {
        $client = $this->makeClient('Mere', 'Tane');
        $agreement = $this->makeActiveAgreement($client, hourlyRate: 50);

        $timesheet = $this->makeApprovedTimesheet($client);

        $entries = $this->service->generateFromTimesheet($timesheet->fresh());

        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame($client->id, $entry->client_id);
        $this->assertEqualsWithDelta((float) $timesheet->total_hours, (float) $entry->hours, 0.01);
        $this->assertSame('50.00', (string) $entry->rate);
        $this->assertSame($agreement->id, $entry->service_agreement_id);
    }

    public function test_three_residential_allocations_produce_three_entries_with_hours_preserved(): void
    {
        $primary = $this->makeClient('Hana', 'One');
        $second = $this->makeClient('Iwi', 'Two');
        $third = $this->makeClient('Jase', 'Three');

        $this->makeActiveAgreement($primary, hourlyRate: 40);
        $this->makeActiveAgreement($second, hourlyRate: 60);
        $this->makeActiveAgreement($third, hourlyRate: 80);

        $timesheet = $this->makeApprovedTimesheet($primary, ['break_minutes' => 0]);
        $perClientHours = round($timesheet->total_hours / 3, 2);

        foreach ([$primary, $second, $third] as $i => $client) {
            TimesheetClientAllocation::create([
                'timesheet_id' => $timesheet->id,
                'client_id' => $client->id,
                'hours' => $perClientHours,
                'allocation_method' => TimesheetClientAllocation::METHOD_RESIDENTIAL_HOUSE,
                'sort_order' => $i,
            ]);
        }

        $entries = $this->service->generateFromTimesheet($timesheet->fresh());

        $this->assertCount(3, $entries);
        $this->assertEqualsWithDelta(
            (float) $timesheet->total_hours,
            (float) $entries->sum(fn ($e) => (float) $e->hours),
            0.03,
        );

        $byClient = $entries->keyBy('client_id');
        $this->assertSame('40.00', (string) $byClient[$primary->id]->rate);
        $this->assertSame('60.00', (string) $byClient[$second->id]->rate);
        $this->assertSame('80.00', (string) $byClient[$third->id]->rate);
    }

    public function test_manual_weighted_allocation_70_30_produces_two_entries_with_correct_amounts(): void
    {
        $clientA = $this->makeClient('Alpha', 'Aone');
        $clientB = $this->makeClient('Bravo', 'Btwo');

        $this->makeActiveAgreement($clientA, hourlyRate: 100);
        $this->makeActiveAgreement($clientB, hourlyRate: 100);

        // 10-hour shift, 70/30 manual split → 7h + 3h.
        $timesheet = $this->makeApprovedTimesheet(
            $clientA,
            [
                'starts_at' => Carbon::parse('2026-05-10 08:00:00'),
                'ends_at' => Carbon::parse('2026-05-10 18:00:00'),
                'break_minutes' => 0,
            ],
        );

        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $clientA->id,
            'hours' => 7.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 0,
        ]);
        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $clientB->id,
            'hours' => 3.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 1,
        ]);

        $entries = $this->service->generateFromTimesheet($timesheet->fresh());

        $this->assertCount(2, $entries);

        $byClient = $entries->keyBy('client_id');
        $this->assertSame('7.00', (string) $byClient[$clientA->id]->hours);
        $this->assertSame('3.00', (string) $byClient[$clientB->id]->hours);
        $this->assertSame('700.00', (string) $byClient[$clientA->id]->amount);
        $this->assertSame('300.00', (string) $byClient[$clientB->id]->amount);
    }

    public function test_rerunning_generation_does_not_duplicate_billing_entries(): void
    {
        $primary = $this->makeClient('Replay', 'Primary');
        $secondary = $this->makeClient('Replay', 'Secondary');

        $this->makeActiveAgreement($primary, hourlyRate: 55);
        $this->makeActiveAgreement($secondary, hourlyRate: 65);

        $timesheet = $this->makeApprovedTimesheet($primary);

        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $primary->id,
            'hours' => 4.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 0,
        ]);
        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $secondary->id,
            'hours' => 4.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 1,
        ]);

        $first = $this->service->generateFromTimesheet($timesheet->fresh());
        $second = $this->service->generateFromTimesheet($timesheet->fresh());

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        $this->assertSame(2, BillingEntry::where('timesheet_id', $timesheet->id)->count());
    }

    public function test_invoiced_entries_are_preserved_when_regenerating(): void
    {
        $primary = $this->makeClient('Locked', 'Primary');
        $secondary = $this->makeClient('Editable', 'Secondary');

        $this->makeActiveAgreement($primary, hourlyRate: 55);
        $this->makeActiveAgreement($secondary, hourlyRate: 65);

        $timesheet = $this->makeApprovedTimesheet($primary);

        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $primary->id,
            'hours' => 5.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 0,
        ]);
        TimesheetClientAllocation::create([
            'timesheet_id' => $timesheet->id,
            'client_id' => $secondary->id,
            'hours' => 3.00,
            'allocation_method' => TimesheetClientAllocation::METHOD_MANUAL,
            'sort_order' => 1,
        ]);

        $first = $this->service->generateFromTimesheet($timesheet->fresh());
        $this->assertCount(2, $first);

        // Lock the primary client's entry by marking it invoiced.
        $primaryEntry = $first->firstWhere('client_id', $primary->id);
        $primaryEntry->update(['status' => 'invoiced']);

        // Re-run generation — the invoiced entry should remain untouched,
        // and the secondary client's pending entry should be replaced.
        $second = $this->service->generateFromTimesheet($timesheet->fresh());

        // Only the secondary client gets recreated (primary is locked).
        $this->assertCount(1, $second);
        $this->assertSame($secondary->id, $second->first()->client_id);

        // Both entries still on the timesheet.
        $this->assertSame(2, BillingEntry::where('timesheet_id', $timesheet->id)->count());

        // Primary entry is the same record, still invoiced.
        $primaryEntry->refresh();
        $this->assertSame('invoiced', $primaryEntry->status);
    }

    public function test_unapproved_timesheet_yields_no_entries(): void
    {
        $client = $this->makeClient('Unfit', 'Forbilling');
        $this->makeActiveAgreement($client, hourlyRate: 50);

        $timesheet = $this->makeApprovedTimesheet($client);
        $timesheet->forceFill(['status' => 'submitted'])->saveQuietly();

        $entries = $this->service->generateFromTimesheet($timesheet->fresh());

        $this->assertCount(0, $entries);
        $this->assertSame(0, BillingEntry::where('timesheet_id', $timesheet->id)->count());
    }

    private function makeClient(string $first, string $last): Client
    {
        return Client::factory()->create([
            'organization_id' => 1,
            'first_name' => $first,
            'last_name' => $last,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);
    }

    private function makeActiveAgreement(Client $client, float $hourlyRate): ServiceAgreement
    {
        return ServiceAgreement::factory()->create([
            'organization_id' => 1,
            'client_id' => $client->id,
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'hourly_rate' => $hourlyRate,
        ]);
    }

    private function makeApprovedTimesheet(Client $client, array $overrides = []): Timesheet
    {
        $startsAt = $overrides['starts_at'] ?? Carbon::parse('2026-05-10 09:00:00');
        $endsAt = $overrides['ends_at'] ?? Carbon::parse('2026-05-10 17:00:00');

        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'user_id' => $this->staff->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'actual_starts_at' => $startsAt,
            'actual_ends_at' => $endsAt,
            'status' => 'completed',
            'created_by' => $this->staff->id,
            'started_by' => $this->staff->id,
            'completed_by' => $this->staff->id,
        ]);

        $attendance = HrAttendanceSession::query()->create([
            'tenant_id' => 1,
            'user_id' => $this->staff->id,
            'shift_id' => $shift->id,
            'site_id' => $shift->site_id,
            'clock_in_at' => $startsAt,
            'clock_out_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'closed',
            'source' => 'manual',
            'created_by' => $this->staff->id,
            'closed_by' => $this->staff->id,
        ]);

        return Timesheet::query()->create(array_merge([
            'user_id' => $this->staff->id,
            'client_id' => $client->id,
            'shift_id' => $shift->id,
            'attendance_session_id' => $attendance->id,
            'shift_site_id' => $shift->site_id,
            'work_date' => $startsAt->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'break_minutes' => 0,
            'status' => 'approved',
            'submitted_at' => Carbon::parse('2026-05-11 09:00:00'),
            'submitted_by' => $this->staff->id,
            'approved_at' => Carbon::parse('2026-05-11 10:00:00'),
            'approved_by' => $this->staff->id,
            'created_by' => $this->staff->id,
            'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => 'Residential',
            'client_name_snapshot' => trim($client->first_name.' '.$client->last_name),
            'staff_name_snapshot' => $this->staff->name,
            'shift_type_snapshot' => 'standard',
            'coverage_roles_snapshot' => [],
        ], $overrides));
    }
}
