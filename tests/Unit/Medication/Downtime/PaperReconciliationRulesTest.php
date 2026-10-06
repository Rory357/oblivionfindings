<?php

namespace Tests\Unit\Medication\Downtime;

use App\Services\Medication\Downtime\PaperReconciliationRules;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PaperReconciliationRulesTest extends TestCase
{
    public function test_nz_dates_are_converted_to_the_actual_utc_instant(): void
    {
        $this->assertSame('2026-10-02T20:10:00+00:00', PaperReconciliationRules::instant('2026-10-03T09:10')->toIso8601String());
        $this->assertSame('2026-06-14T21:10:00+00:00', PaperReconciliationRules::instant('2026-06-15T09:10')->toIso8601String());
    }

    public function test_nonexistent_spring_clock_time_is_not_silently_shifted(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaperReconciliationRules::instant('2026-09-27T02:30');
    }

    public function test_repeated_autumn_clock_time_requires_the_offset_on_paper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaperReconciliationRules::instant('2026-04-05T02:30');
    }

    public function test_explicit_offsets_keep_the_two_autumn_instants_distinct(): void
    {
        $first = PaperReconciliationRules::instant('2026-04-05T02:30+13:00');
        $second = PaperReconciliationRules::instant('2026-04-05T02:30+12:00');
        $this->assertSame(3600, $second->getTimestamp() - $first->getTimestamp());
    }

    public function test_offset_timestamp_cannot_normalize_an_impossible_calendar_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PaperReconciliationRules::instant('2026-02-30T09:10+13:00');
    }

    public function test_scheduled_identity_uses_the_slot_even_when_dst_slots_share_an_instant(): void
    {
        $at = CarbonImmutable::parse('2026-09-26T14:00:00Z');
        $this->assertNotSame(PaperReconciliationRules::identity(4, 10, $at), PaperReconciliationRules::identity(4, 11, $at));
        $this->assertSame(PaperReconciliationRules::identity(4, 10, $at), PaperReconciliationRules::identity(4, 10, $at->addMinutes(20)));
    }

    public function test_prn_identity_deduplicates_the_same_actual_minute(): void
    {
        $at = CarbonImmutable::parse('2026-10-02T20:10:00Z');
        $this->assertSame(PaperReconciliationRules::identity(4, null, $at), PaperReconciliationRules::identity(4, null, $at->addSeconds(20)));
        $this->assertNotSame(PaperReconciliationRules::identity(4, null, $at), PaperReconciliationRules::identity(4, null, $at->addMinute()));
    }

    public function test_fingerprints_are_stable_with_reordered_maps_but_change_when_paper_facts_change(): void
    {
        $this->assertSame(PaperReconciliationRules::fingerprint(['giver' => 4, 'reading' => ['b' => 2, 'a' => 1]]), PaperReconciliationRules::fingerprint(['reading' => ['a' => 1, 'b' => 2], 'giver' => 4]));
        $this->assertNotSame(PaperReconciliationRules::fingerprint(['giver' => 4]), PaperReconciliationRules::fingerprint(['giver' => 5]));
    }

    public function test_paper_confirmation_alone_never_reads_as_entered_from_paper(): void
    {
        $this->assertSame('giver_to_confirm', PaperReconciliationRules::state(false, false, true, false));
        $this->assertSame('witness_to_confirm', PaperReconciliationRules::state(false, true, true, false));
        $this->assertSame('ready_to_reconcile', PaperReconciliationRules::state(false, true, true, true));
        $this->assertSame('ready_to_reconcile', PaperReconciliationRules::state(false, true, false, false));
        $this->assertSame('entered_from_paper', PaperReconciliationRules::state(true, true, true, true));
    }

    public function test_downtime_boundaries_are_inclusive(): void
    {
        $start = CarbonImmutable::parse('2026-10-02T20:00:00Z');
        $end = $start->addHour();
        $this->assertTrue(PaperReconciliationRules::inside($start, $start, $end));
        $this->assertTrue(PaperReconciliationRules::inside($end, $start, $end));
        $this->assertFalse(PaperReconciliationRules::inside($end->addSecond(), $start, $end));
    }
}
