<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Models\ClinicalProtocol;
use App\Domain\Clinical\Models\ClinicalProtocolSchedule;
use App\Domain\Clinical\Services\ClinicalProtocolService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\ShiftTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClinicalProtocolServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ClinicalProtocolService $service;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ClinicalProtocolService::class);
        $this->client = Client::factory()->create();
    }

    // ── getDueForClient() ────────────────────────────────────────────────

    public function test_get_due_for_client(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->subHour(),
            'status' => 'pending',
        ]);
        ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);

        $due = $this->service->getDueForClient($this->client);

        $this->assertCount(1, $due);
    }

    public function test_get_due_excludes_other_clients(): void
    {
        $otherClient = Client::factory()->create();
        $otherProtocol = ClinicalProtocol::factory()->create(['client_id' => $otherClient->id]);
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $otherProtocol->id,
            'status' => 'pending',
        ]);

        $due = $this->service->getDueForClient($this->client);
        $this->assertCount(0, $due);
    }

    // ── getDueForShift() ─────────────────────────────────────────────────

    public function test_get_due_for_shift_every_shift_protocol(): void
    {
        $protocol = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $due = $this->service->getDueForShift($shift);

        $this->assertCount(1, $due);
        $this->assertEquals($protocol->id, $due[0]['protocol']->id);
        $this->assertNull($due[0]['schedule']); // EveryShift has no pre-generated schedule
    }

    public function test_every_shift_protocol_not_due_if_already_recorded(): void
    {
        $protocol = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        // Record an observation for this shift
        ClinicalObservation::factory()->vitals()->create([
            'client_id' => $this->client->id,
            'shift_id' => $shift->id,
        ]);

        $due = $this->service->getDueForShift($shift);
        $this->assertCount(0, $due);
    }

    public function test_get_due_for_shift_time_based_protocol(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => Carbon::parse('2026-04-14 08:00'),
            'ends_at' => Carbon::parse('2026-04-14 16:00'),
        ]);

        // Create a schedule item within the shift window
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => Carbon::parse('2026-04-14 10:00'),
            'status' => 'pending',
        ]);

        // Create one outside the shift window
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => Carbon::parse('2026-04-14 20:00'),
            'status' => 'pending',
        ]);

        $due = $this->service->getDueForShift($shift);
        $this->assertCount(1, $due);
    }

    public function test_get_due_for_shift_with_no_protocols(): void
    {
        // Client with no active protocols — shift should have nothing due
        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $due = $this->service->getDueForShift($shift);
        $this->assertCount(0, $due);
    }

    // ── generateShiftTasks() ─────────────────────────────────────────────

    public function test_generates_shift_tasks_for_due_items(): void
    {
        $protocol = ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $tasks = $this->service->generateShiftTasks($shift);

        $this->assertCount(1, $tasks);
        $this->assertInstanceOf(ShiftTask::class, $tasks[0]);
        $this->assertEquals($shift->id, $tasks[0]->shift_id);
        $this->assertStringContainsString('Vital Signs', $tasks[0]->label);
        $this->assertFalse($tasks[0]->is_completed);
    }

    public function test_shift_task_generation_is_idempotent(): void
    {
        ClinicalProtocol::factory()->everyShiftVitals()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
        ]);

        $first = $this->service->generateShiftTasks($shift);
        $second = $this->service->generateShiftTasks($shift);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second); // deduped
        $this->assertEquals(1, ShiftTask::where('shift_id', $shift->id)->count());
    }

    public function test_links_schedule_item_to_created_task(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => Carbon::parse('2026-04-14 08:00'),
            'ends_at' => Carbon::parse('2026-04-14 16:00'),
        ]);

        $schedule = ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => Carbon::parse('2026-04-14 10:00'),
            'status' => 'pending',
        ]);

        $tasks = $this->service->generateShiftTasks($shift);

        $this->assertCount(1, $tasks);
        $schedule->refresh();
        $this->assertEquals($tasks[0]->id, $schedule->shift_task_id);
    }

    public function test_skips_schedule_with_existing_task_link(): void
    {
        $protocol = ClinicalProtocol::factory()->dailyWeight()->create([
            'client_id' => $this->client->id,
        ]);

        $shift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'starts_at' => Carbon::parse('2026-04-14 08:00'),
            'ends_at' => Carbon::parse('2026-04-14 16:00'),
        ]);

        $existingTask = ShiftTask::create([
            'shift_id' => $shift->id,
            'label' => 'Pre-existing task',
            'sort_order' => 0,
        ]);

        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => Carbon::parse('2026-04-14 10:00'),
            'status' => 'pending',
            'shift_task_id' => $existingTask->id,
        ]);

        $tasks = $this->service->generateShiftTasks($shift);
        $this->assertCount(0, $tasks);
    }

    // ── getOverdue() ─────────────────────────────────────────────────────

    public function test_get_overdue(): void
    {
        $protocol = ClinicalProtocol::factory()->create([
            'client_id' => $this->client->id,
        ]);

        ClinicalProtocolSchedule::factory()->overdue()->create([
            'clinical_protocol_id' => $protocol->id,
        ]);
        ClinicalProtocolSchedule::factory()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $overdue = $this->service->getOverdue($this->client);
        $this->assertCount(1, $overdue);
    }

    // ── getComplianceRate() ──────────────────────────────────────────────

    public function test_compliance_rate_100_when_all_completed(): void
    {
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->client->id]);

        ClinicalProtocolSchedule::factory()
            ->completed()
            ->count(3)
            ->sequence(
                ['due_at' => now()->subDays(5)],
                ['due_at' => now()->subDays(4)],
                ['due_at' => now()->subDays(3)],
            )
            ->create(['clinical_protocol_id' => $protocol->id]);

        $rate = $this->service->getComplianceRate($this->client);
        $this->assertEquals(100.0, $rate);
    }

    public function test_compliance_rate_with_mixed_statuses(): void
    {
        $protocol = ClinicalProtocol::factory()->create(['client_id' => $this->client->id]);

        ClinicalProtocolSchedule::factory()->completed()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->subDays(3),
        ]);
        ClinicalProtocolSchedule::factory()->missed()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->subDays(2),
        ]);
        ClinicalProtocolSchedule::factory()->skipped()->create([
            'clinical_protocol_id' => $protocol->id,
            'due_at' => now()->subDay(),
        ]);

        $rate = $this->service->getComplianceRate($this->client);
        $this->assertEquals(50.0, $rate);
    }

    public function test_compliance_rate_is_zero_when_no_schedules(): void
    {
        $rate = $this->service->getComplianceRate($this->client);
        $this->assertEquals(0.0, $rate);
    }
}
