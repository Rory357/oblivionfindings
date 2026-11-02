<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * G4-11 (board approved by Stephan 1 Nov 2026): the Protective gear header counts and the one lead block. The counts
 * are for the selected Site only, overdue equipment leads (oldest date first) with its problems grouped, gear handed
 * out without a fit test follows, other Sites never show, and only people who may inspect get the inspect step. The
 * table lists problems first.
 */
class PpeRegisterSummaryTest extends PpeCommandTestCase
{
    private function gear(array $facts, string $category = 'other'): PpeInventory
    {
        return PpeInventory::factory()->create(['site_id' => $this->site->id, 'status' => 'available', 'condition' => 'good',
            'next_inspection_due' => null, 'expiry_date' => null, 'ppe_type_id' => PpeType::factory()->create(['category' => $category])->id, ...$facts]);
    }

    public function test_the_header_counts_and_lead_block_follow_the_selected_site(): void
    {
        $harness = $this->gear(['serial_number' => 'HAR-0042', 'condition' => 'poor', 'next_inspection_due' => '2026-08-12', 'expiry_date' => '2026-10-01', 'location' => 'laundry']);
        $respirator = $this->gear(['serial_number' => 'RSP-6200-014', 'condition' => 'fair', 'next_inspection_due' => '2026-06-02']);
        $this->gear(['serial_number' => 'FINE-1', 'next_inspection_due' => '2027-01-01', 'expiry_date' => '2027-06-01']);
        $this->gear(['serial_number' => 'GONE-1', 'status' => 'condemned', 'next_inspection_due' => '2026-01-01']);
        $p2 = $this->gear(['serial_number' => 'RSP-P2-031', 'status' => 'allocated'], 'respiratory');
        $handedOut = PpeAllocation::factory()->create(['ppe_inventory_id' => $p2->id, 'user_id' => $this->worker->id, 'allocated_at' => Carbon::parse('2026-10-01T01:00:00Z'),
            'returned_at' => null, 'fit_test_completed' => false, 'acknowledged' => false]);
        $elsewhere = Site::factory()->create(['is_active' => true]);
        PpeInventory::factory()->create(['site_id' => $elsewhere->id, 'status' => 'available', 'next_inspection_due' => '2026-01-01', 'serial_number' => 'OTHER-SITE']);

        $this->actingAs($this->manager)->get('/health-safety/ppe?site_id='.$this->site->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/index')
                ->where('register_summary.boxes.inspections_overdue', 2)
                ->where('register_summary.boxes.past_expiry', 1)
                ->where('register_summary.boxes.fit_tests_missing', 1)
                ->where('register_summary.boxes.handed_out_30d', 1)
                ->where('register_summary.unconfirmed', 1)
                ->where('register_summary.lead_total', 3)
                ->has('register_summary.lead', 3)
                // Oldest overdue date first, then the hand-out without a fit test.
                ->where('register_summary.lead.0.id', $respirator->id)
                ->where('register_summary.lead.0.due', '2026-06-02')
                ->where('register_summary.lead.0.problems', ['inspection_overdue'])
                ->where('register_summary.lead.1.id', $harness->id)
                ->where('register_summary.lead.1.problems', ['inspection_overdue', 'past_expiry', 'poor_condition'])
                ->where('register_summary.lead.1.can_inspect', true)
                ->where('register_summary.lead.2.kind', 'allocation')
                ->where('register_summary.lead.2.id', $handedOut->id)
                ->where('register_summary.lead.2.problems', ['no_fit_test'])
                // The table lists problems first.
                ->where('inventory.data.0.id', fn ($id) => in_array($id, [$harness->id, $respirator->id], true))
                ->where('inventory.data.1.id', fn ($id) => in_array($id, [$harness->id, $respirator->id], true)));
    }

    public function test_a_reader_without_the_manage_permission_gets_no_inspect_step(): void
    {
        $this->gear(['next_inspection_due' => '2026-06-02']);
        $reader = $this->staff(['hazards.view']);

        $this->actingAs($reader)->get('/health-safety/ppe')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('register_summary.lead.0.can_inspect', false));
    }
}
