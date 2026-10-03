<?php

namespace Tests\Unit;

use App\Services\Medication\Followups\FollowupTime;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class MedicationFollowupTimeTest extends TestCase
{
    public function test_both_occurrences_of_the_repeated_hour_keep_their_explicit_offsets(): void
    {
        $first = FollowupTime::parse('2026-04-05T02:30:00+13:00');
        $second = FollowupTime::parse('2026-04-05T02:30:00+12:00');
        $this->assertSame(3600.0, $first->diffInSeconds($second));
    }

    public function test_spring_forward_gap_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        FollowupTime::parse('2026-09-27T02:30:00+12:00');
    }

    public function test_an_ambiguous_wall_clock_without_an_offset_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        FollowupTime::parse('2026-04-05T02:30');
    }
}
