<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\HealthSafety\HsReviewPdfService;

/**
 * G3 board M5c (approved by Stephan 1 Nov 2026; decisions 21, 25): the review's PDF — the final record once closed,
 * a summary marked "in progress, not final" otherwise — for people who can read the whole review only; every page says
 * it's confidential, who downloaded it and when; every download is logged.
 */
class HsReviewPdfTest extends RestraintEventCommandTestCase
{
    private const MANAGER = ['hazards.view', 'hazards.manage'];

    private function review(array $attributes = []): HsEvent
    {
        return HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
            'site_id' => $this->site->id, 'event_category' => HsEvent::CATEGORY_HAZARD, 'client_id' => null,
            'status' => HsEvent::STATUS_INVESTIGATING, 'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED, ...$attributes,
        ]));
    }

    public function test_a_reader_downloads_the_pdf_and_it_is_logged(): void
    {
        $viewer = $this->staff(self::MANAGER);
        $event = $this->review(['status' => HsEvent::STATUS_CLOSED, 'closed_at' => now(), 'closure_summary' => 'Rugs need a non-slip backing.']);

        $response = $this->actingAs($viewer)->get("/health-safety/events/{$event->id}/pdf?photos=1")->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('final-record-'.$event->reference_number, (string) $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'healthSafety.event.pdfDownloaded', 'auditable_id' => $event->id, 'user_id' => $viewer->id]);
        $what = collect($this->actingAs($viewer)->getJson("/health-safety/events/{$event->id}/activity")->json('entries'))->pluck('what');
        $this->assertContains('downloaded the PDF', $what->all());
    }

    public function test_the_html_says_confidential_who_and_whether_it_is_final(): void
    {
        $viewer = $this->staff(self::MANAGER);
        $viewer->forceFill(['name' => 'Dana Hall'])->save();
        $open = $this->review();
        $detail = ['id' => $open->id, 'reference_number' => $open->reference_number, 'title' => 'Hazard — Kauri House', 'status' => 'investigating',
            'severity' => 'medium', 'site' => ['id' => $this->site->id, 'name' => $this->site->name], 'worksafe' => [], 'corrective_actions' => [],
            'investigations' => [], 'handover' => ['owner' => null], 'close_readiness' => ['requirements' => []], 'handover_summary' => []];

        $html = app(HsReviewPdfService::class)->html($open, $detail, $viewer, false);
        $this->assertStringContainsString('Confidential — contains health information. Downloaded by Dana Hall', $html);
        $this->assertStringContainsString('in progress, not final', $html);
        $this->assertStringNotContainsString('frozen when it closed', $html);
    }

    public function test_people_who_cannot_read_the_review_get_no_pdf(): void
    {
        $event = $this->review();
        $elsewhere = $this->staff(self::MANAGER, \App\Models\Site::factory()->create(['is_active' => true]));

        $this->actingAs($elsewhere)->get("/health-safety/events/{$event->id}/pdf")->assertNotFound();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'healthSafety.event.pdfDownloaded']);
        $this->assertContains($this->actingAs($this->staff([]))->get("/health-safety/events/{$event->id}/pdf")->getStatusCode(), [403, 404]);
    }
}
