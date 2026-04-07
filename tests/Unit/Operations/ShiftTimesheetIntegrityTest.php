<?php

namespace Tests\Unit\Operations;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ShiftTimesheetIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_timesheet_model_returns_validation_error_for_duplicate_shift_user_pairs(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
        ]);

        Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
        ]);

        try {
            Timesheet::query()->create([
                'user_id' => $user->id,
                'client_id' => $client->id,
                'shift_id' => $shift->id,
                'work_date' => now()->toDateString(),
                'starts_at' => now()->copy()->setTime(9, 0),
                'ends_at' => now()->copy()->setTime(17, 0),
                'break_minutes' => 30,
                'status' => 'draft',
                'created_by' => $user->id,
            ]);

            $this->fail('Expected duplicate timesheet validation to be thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['A timesheet already exists for this shift and staff member.'],
                $exception->errors()['shift_id'] ?? []
            );
        }
    }

    public function test_timesheet_model_blocks_submission_when_shift_is_cancelled(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => 'cancelled',
        ]);

        $timesheet = Timesheet::factory()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
            'status' => 'draft',
        ]);

        $this->expectException(ValidationException::class);

        $timesheet->update([
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $user->id,
        ]);
    }

    public function test_shift_model_blocks_payroll_critical_mutations_when_approved_timesheet_exists(): void
    {
        $client = Client::factory()->create();
        $user = User::factory()->create();
        $shift = Shift::factory()->create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(17, 0),
            'status' => 'scheduled',
        ]);

        Timesheet::factory()->approved()->create([
            'shift_id' => $shift->id,
            'client_id' => $client->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(ValidationException::class);

        $shift->update([
            'starts_at' => $shift->starts_at->copy()->addHour(),
            'ends_at' => $shift->ends_at->copy()->addHour(),
        ]);
    }
}
