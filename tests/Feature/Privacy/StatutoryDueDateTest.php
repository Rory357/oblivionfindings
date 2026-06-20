<?php

namespace Tests\Feature\Privacy;

use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Privacy\Services\StatutoryDueDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryDueDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_is_20_working_days_skipping_weekends(): void
    {
        // Mon 2026-06-01 + 20 working days (no holidays) = Mon 2026-06-29.
        $due = app(StatutoryDueDate::class)->dueFrom('2026-06-01');

        $this->assertSame('2026-06-29', $due->toDateString());
    }

    public function test_due_skips_public_holidays(): void
    {
        HrPublicHoliday::create([
            'name' => 'Test Holiday',
            'date' => '2026-06-03',
            'year' => 2026,
            'is_national' => true,
        ]);

        // The holiday pushes the deadline out one working day -> Tue 2026-06-30.
        $due = app(StatutoryDueDate::class)->dueFrom('2026-06-01');

        $this->assertSame('2026-06-30', $due->toDateString());
    }

    public function test_statutory_window_is_twenty_working_days(): void
    {
        $this->assertSame(20, StatutoryDueDate::WORKING_DAYS);
    }
}
