<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftConflictService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftConflictService $service;

    protected User $staff;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftConflictService::class);
        $this->staff = User::factory()->create();
        $this->client = Client::factory()->create();
    }

    public function test_blocking_overlap_is_detected_for_same_staff_member(): void
    {
        $existing = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $conflicts = $this->service->findBlockingStaffConflicts(
            $this->staff->id,
            now()->setTime(12, 0),
            now()->setTime(16, 0),
        );

        $this->assertSame([$existing->id], $conflicts->pluck('id')->all());
    }

    public function test_exact_boundary_times_do_not_count_as_blocking_overlap(): void
    {
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $conflicts = $this->service->findBlockingStaffConflicts(
            $this->staff->id,
            now()->setTime(13, 0),
            now()->setTime(17, 0),
        );

        $this->assertCount(0, $conflicts);
    }

    public function test_tight_turnaround_warns_without_hard_blocking(): void
    {
        $existing = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(6, 0),
            'ends_at' => now()->setTime(10, 0),
            'status' => 'scheduled',
        ]);

        $conflicts = $this->service->findBlockingStaffConflicts(
            $this->staff->id,
            now()->setTime(10, 20),
            now()->setTime(14, 0),
        );
        $warnings = $this->service->findTightTurnaroundWarnings(
            $this->staff->id,
            now()->setTime(10, 20),
            now()->setTime(14, 0),
        );

        $this->assertCount(0, $conflicts);
        $this->assertSame([$existing->id], $warnings->pluck('id')->all());
    }

    public function test_overnight_shifts_crossing_midnight_are_checked_correctly(): void
    {
        $existing = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(22, 0),
            'ends_at' => now()->addDay()->setTime(6, 0),
            'status' => 'scheduled',
        ]);

        $conflicts = $this->service->findBlockingStaffConflicts(
            $this->staff->id,
            now()->addDay()->setTime(5, 30),
            now()->addDay()->setTime(8, 0),
        );

        $this->assertSame([$existing->id], $conflicts->pluck('id')->all());
    }

    public function test_cancelled_and_completed_shifts_do_not_block_or_warn(): void
    {
        Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => 'cancelled',
        ]);
        // Use the completed() factory state so the shift is created with the
        // actual_starts_at / actual_ends_at evidence the safety invariant
        // requires for completed shifts.
        Shift::factory()->completed()->create([
            'client_id' => $this->client->id,
            'user_id' => $this->staff->id,
            'starts_at' => now()->setTime(13, 15),
            'ends_at' => now()->setTime(17, 0),
        ]);

        $conflicts = $this->service->findBlockingStaffConflicts(
            $this->staff->id,
            now()->setTime(12, 0),
            now()->setTime(16, 0),
        );
        $warnings = $this->service->findTightTurnaroundWarnings(
            $this->staff->id,
            now()->setTime(17, 15),
            now()->setTime(20, 0),
        );

        $this->assertCount(0, $conflicts);
        $this->assertCount(0, $warnings);
    }

    public function test_client_overlap_warning_finds_relevant_overlapping_shift(): void
    {
        $otherStaff = User::factory()->create();
        $existing = Shift::factory()->create([
            'client_id' => $this->client->id,
            'user_id' => $otherStaff->id,
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(13, 0),
            'status' => 'scheduled',
        ]);

        $warnings = $this->service->findClientOverlapWarnings(
            $this->client->id,
            now()->setTime(12, 0),
            now()->setTime(16, 0),
        );

        $this->assertSame([$existing->id], $warnings->pluck('id')->all());
    }
}
