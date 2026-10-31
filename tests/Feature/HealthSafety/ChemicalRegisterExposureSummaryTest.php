<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;

/**
 * Stephan (26 Oct), on the chemical register's exposure numbers: "Anyone who sees the list", as on main before the H&S
 * merge. Counts only, at the viewer's Sites; the private exposure list is linked only for people who can read it.
 */
final class ChemicalRegisterExposureSummaryTest extends ChemicalCommandTestCase
{
    private function exposure(array $facts): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'site_id' => $this->site->id, 'exposed_at' => now()->subDays(3), 'medical_attention_sought' => false,
            'medical_outcome' => null, 'symptoms' => 'PRIVATE-SYMPTOMS', ...$facts]));
    }

    private function summary($viewer, string $query = ''): array
    {
        return $this->actingAs($viewer)->getJson('/health-safety/substances'.$query)->assertOk()->json('exposure_summary');
    }

    public function test_anyone_who_sees_the_list_gets_the_counts_for_their_sites_without_private_detail(): void
    {
        $this->exposure([]);
        $this->exposure(['medical_attention_sought' => true]);
        $this->exposure(['medical_attention_sought' => true, 'medical_outcome' => 'Recovered fully.']);
        $this->exposure(['exposed_at' => now()->subDays(200)]);
        $this->exposure(['site_id' => $this->foreignSite->id, 'medical_attention_sought' => true]);
        $notifiable = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['event_category' => HsEvent::CATEGORY_EXPOSURE,
            'site_id' => $this->site->id, 'worksafe_notifiable' => true, 'worksafe_status' => HsEvent::WORKSAFE_PENDING]));
        HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['event_category' => HsEvent::CATEGORY_EXPOSURE,
            'site_id' => $this->foreignSite->id, 'worksafe_notifiable' => true, 'worksafe_status' => null]));
        $reader = $this->staff(['hazards.view']);

        $response = $this->actingAs($reader)->getJson('/health-safety/substances')->assertOk();
        $this->assertSame(['exposures' => 3, 'awaiting_outcome' => 1, 'worksafe_awaiting' => 1, 'period_days' => 90,
            'exposures_href' => null], $response->json('exposure_summary'));
        $this->assertStringNotContainsString('PRIVATE-', $response->getContent());
        $this->assertNotNull($notifiable->id);
    }

    public function test_the_private_exposure_list_is_linked_only_for_exposure_readers(): void
    {
        $this->assertNull($this->summary($this->staff(['hazards.view']))['exposures_href']);
        $this->assertSame('/health-safety/chemical-exposures', $this->summary($this->staff(['hazards.view', 'hr.wellbeing.view']))['exposures_href']);
    }

    public function test_the_selected_site_and_period_narrow_the_counts(): void
    {
        $this->exposure(['exposed_at' => now()->subDays(10)]);
        $reader = $this->staff(['hazards.view']);

        $this->assertSame(1, $this->summary($reader, '?site_id='.$this->site->id)['exposures']);
        $this->assertSame(0, $this->summary($reader, '?period=7')['exposures']);
        $this->assertSame(7, $this->summary($reader, '?period=7')['period_days']);
    }
}
