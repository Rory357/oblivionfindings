<?php

namespace Tests\Feature\HealthSafety;

use App\Http\Controllers\HealthSafety\HsEventController;
use App\Models\HsEvent;
use App\Models\RestraintEvent;
use App\Services\HealthSafety\RestraintRegisterService;
use App\Services\HealthSafety\RestraintSourceProjectionService;

/**
 * Restraint summary (Stephan, 2 Nov 2026: "Add a summary box"): one short "what happened" line on the report form, so the
 * restraint's title reads in plain words on its record, its safety review and its lists. Older records with no summary
 * keep "Restraint — <person>". The line is short (at most 120 characters) and one line.
 */
class RestraintSummaryTitleTest extends RestraintEventCommandTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->update(['first_name' => 'Aroha', 'last_name' => 'Kingi']);
    }

    public function test_the_summary_titles_the_record_and_its_register_row(): void
    {
        $event = $this->event(['summary' => "  Held arm\n to stop a fall  "]);

        $this->assertSame('Held arm to stop a fall', $event->summary, 'One line: spaces and line breaks collapse.');
        $detail = app(RestraintSourceProjectionService::class)->event($event, $this->actor);
        $this->assertSame('Held arm to stop a fall — Aroha Kingi', $detail['event']['title']);
        $this->assertSame('Held arm to stop a fall', $detail['event']['summary']);

        $rows = app(RestraintRegisterService::class)->register($this->actor)['events']['data'];
        $this->assertSame(['Held arm to stop a fall — Aroha Kingi'], array_column($rows, 'title'));
    }

    public function test_an_older_record_with_no_summary_keeps_restraint_and_the_person(): void
    {
        $event = $this->event();
        $blank = $this->event(['summary' => '   ']);

        $this->assertNull($blank->summary, 'Blank is no summary.');
        $this->assertSame('Restraint — Aroha Kingi', app(RestraintSourceProjectionService::class)->event($event, $this->actor)['event']['title']);
        $this->assertSame('Restraint — Aroha Kingi', app(RestraintSourceProjectionService::class)->event($blank, $this->actor)['event']['title']);
    }

    public function test_the_summary_is_short_and_validated(): void
    {
        $this->invalid(fn () => $this->event(['summary' => str_repeat('a', 121)]), 'summary');
        $this->invalid(fn () => $this->event(['summary' => ['not', 'text']]), 'summary');
        $this->assertSame(120, mb_strlen($this->event(['summary' => str_repeat('a', 120)])->summary));
    }

    public function test_a_correction_changes_the_summary_and_the_title_follows(): void
    {
        $event = $this->event(['summary' => 'Held arm']);

        $this->service()->correct($event, $this->actor, $this->command($event, ['summary' => 'Held arm to stop a fall', 'reason' => 'Clearer words']));

        $this->assertSame('Held arm to stop a fall — Aroha Kingi', app(RestraintSourceProjectionService::class)->event($event->fresh(), $this->actor)['event']['title']);
    }

    public function test_the_safety_review_uses_the_summary_only_for_people_who_can_read_the_restraint(): void
    {
        $event = $this->event(['summary' => 'Held arm to stop a fall']);
        $review = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $this->client->id, 'event_category' => HsEvent::CATEGORY_RESTRAINT,
            'source_type' => RestraintEvent::class, 'source_id' => $event->id, 'owner_user_id' => null,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, 'status' => HsEvent::STATUS_OPEN,
        ]))->load(['client', 'site']);
        $viewer = $this->actor;
        $title = fn (?array $source): string => (fn (HsEvent $e, ?array $s) => $this->reviewTitle($e, null, $s, $viewer))
            ->call(app(HsEventController::class), $review, $source);

        $this->assertSame('Held arm to stop a fall — Aroha Kingi', $title(['id' => $event->id]), 'A reader sees the restraint’s own words.');
        $this->assertSame('Restraint — Aroha Kingi', $title(null), 'Someone who can’t read the restraint never sees its summary.');
    }
}
