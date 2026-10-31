<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use App\Services\Sites\Calendar\CalendarItem;
use App\Services\Sites\Calendar\IcsFeedBuilder;
use App\Services\Sites\Calendar\Providers\WorkerParticipationObligationProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual source/provider/ICS/mail objects; no delivery. Native execution remains UNRUN. */
final class WorkerParticipationDateConsumersTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-04-15T12:30:00Z'));
        $this->site->update(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->staff(['hazards.view', 'calendar.view', 'sites.viewAny', 'sites.type.house.view']);
        $this->committee = HsCommittee::create(['name' => 'Civil cadence committee', 'site_id' => $this->site->id, 'status' => 'active',
            'established_at' => '2026-01-01', 'meeting_frequency' => 'monthly']);
        Notification::fake();
    }

    private function read(string $day): array
    {
        return (new WorkerParticipationObligationProvider)->readForViewer([$this->site->id], Carbon::parse($day)->startOfDay(), Carbon::parse($day)->endOfDay(), $this->actor);
    }

    public function test_nz_march_midnight_cadence_survives_dst_as_april_literal_date_and_real_ics_date(): void
    {
        $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'completed']);
        $read = $this->read('2026-04-16');
        $this->assertSame('available', $read['source_access']['status']);
        $due = collect($read['items'])->sole('id', 'participation-cadence-'.$this->committee->id);
        $this->assertSame('2026-04-16', $due['start']); $this->assertTrue($due['allDay']);
        $this->assertSame('scheduled', $due['status']); $this->assertNull($due['priority']);
        $ics = app(IcsFeedBuilder::class)->build([new CalendarItem(...$due)]);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260416', $ics);
        $this->assertStringNotContainsString('DTSTART;VALUE=DATE:20260415', $ics);
        $this->assertSame([], $this->read('2026-04-15')['items']);
    }

    public function test_raw_utc_max_is_not_reinterpreted_when_app_timezone_is_non_utc(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'completed']);
        $this->assertSame('2026-03-15 11:30:00', $meeting->getRawOriginal('scheduled_at'));
        config(['app.timezone' => 'Pacific/Auckland', 'app.worker_timezone' => 'Pacific/Auckland']);
        $read = $this->read('2026-04-16');
        $this->assertSame('available', $read['source_access']['status']);
        $due = collect($read['items'])->sole('id', 'participation-cadence-'.$this->committee->id);
        $this->assertSame('2026-04-16', $due['start']);
        $ics = app(IcsFeedBuilder::class)->build([new CalendarItem(...$due)]);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20260416', $ics);
        $this->assertStringNotContainsString('DTSTART;VALUE=DATE:20260415', $ics);
        $this->assertSame([], $this->read('2026-04-15')['items']);
    }

    public static function frequencies(): array
    {
        return [['weekly', '2026-03-23'], ['fortnightly', '2026-03-30'], ['quarterly', '2026-06-16'], ['legacy', '2026-06-16']];
    }

    #[DataProvider('frequencies')]
    public function test_existing_cadence_frequencies_and_backstop_use_worker_civil_day(string $frequency, string $day): void
    {
        $this->committee->update(['meeting_frequency' => $frequency]);
        $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'cancelled']);
        $due = collect($this->read($day)['items'])->sole('id', 'participation-cadence-'.$this->committee->id);
        $this->assertSame($day, $due['start']);
    }

    public function test_same_local_day_is_not_overdue_and_previous_local_day_is_overdue(): void
    {
        $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'completed']);
        $this->assertSame('scheduled', collect($this->read('2026-04-16')['items'])->sole('id', 'participation-cadence-'.$this->committee->id)['status']);
        $this->travelTo(Carbon::parse('2026-04-16T12:00:00Z'));
        $due = collect($this->read('2026-04-16')['items'])->sole('id', 'participation-cadence-'.$this->committee->id);
        $this->assertSame('overdue', $due['status']); $this->assertSame('high', $due['priority']);
    }

    public function test_existing_month_overflow_arithmetic_is_preserved(): void
    {
        $this->committee->meetings()->create(['scheduled_at' => '2026-01-30 11:30:00', 'status' => 'completed']);
        $due = collect($this->read('2026-03-03')['items'])->sole('id', 'participation-cadence-'.$this->committee->id);
        $this->assertSame('2026-03-03', $due['start']);
    }

    public function test_established_and_consultation_and_term_dates_are_literal_days(): void
    {
        $this->committee->update(['established_at' => '2026-03-16']);
        HsConsultation::create(['title' => 'Literal consultation', 'consultation_type' => 'general', 'description' => 'Not a date operand',
            'site_id' => $this->site->id, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-04-16', 'status' => 'open']);
        HsRepresentative::create(['user_id' => $this->actor->id, 'site_id' => $this->site->id, 'election_method' => 'elected',
            'elected_at' => '2026-01-01', 'term_expires_at' => '2026-04-16', 'status' => 'active']);
        $rows = $this->read('2026-04-16')['items'];
        $this->assertCount(3, $rows);
        foreach ($rows as $row) { $this->assertSame('2026-04-16', $row['start']); $this->assertTrue($row['allDay']); }
    }

    public function test_timed_meeting_remains_absolute_and_actual_http_json_preserves_instant(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-04-16 00:30:00', 'status' => 'scheduled']);
        $response = $this->actingAs($this->actor)->getJson('/sites/'.$this->site->id.'/calendar/events?start=2026-04-16&end=2026-04-16T23:59:59&sources[]=participation');
        $response->assertOk()->assertJsonPath('availability.participation', 'available');
        $row = collect($response->json('events'))->sole('id', 'participation-meeting-'.$meeting->id);
        $this->assertFalse($row['allDay']); $this->assertSame('2026-04-16T00:30:00+00:00', $row['start']);
        $ics = app(IcsFeedBuilder::class)->build([new CalendarItem(...$row)]);
        $this->assertStringContainsString('DTSTART:20260416T003000Z', $ics);
    }

    public function test_actual_mail_formats_worker_zone_without_mutating_original_database_timestamp(): void
    {
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'scheduled']);
        $notice = new CommitteeMeetingScheduled($meeting, $this->committee);
        $original = $meeting->scheduled_at->toIso8601String();
        $mail = $notice->toMail($this->actor);
        $this->assertStringContainsString('Mon 16 Mar 2026, 12:30am (Pacific/Auckland)', implode(' ', $mail->introLines));
        $this->assertSame($original, $meeting->scheduled_at->toIso8601String());
        $this->assertSame($original, $notice->toArray($this->actor)['scheduled_at']->toIso8601String());
        $this->assertSame('2026-03-15 11:30:00', $meeting->getRawOriginal('scheduled_at'));
        Notification::assertNothingSent();
    }

    public function test_empty_worker_zone_uses_existing_app_fallback_for_mail(): void
    {
        config(['app.worker_timezone' => '', 'app.timezone' => 'UTC']);
        $meeting = $this->committee->meetings()->create(['scheduled_at' => '2026-03-15 11:30:00', 'status' => 'scheduled']);
        $mail = (new CommitteeMeetingScheduled($meeting, $this->committee))->toMail($this->actor);
        $this->assertStringContainsString('Sun 15 Mar 2026, 11:30am (UTC)', implode(' ', $mail->introLines));
        Notification::assertNothingSent();
    }
}
