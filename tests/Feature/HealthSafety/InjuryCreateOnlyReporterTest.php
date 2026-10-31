<?php

namespace Tests\Feature\HealthSafety;

use Illuminate\Support\Facades\Queue;

/**
 * MASTER for Stephan (30 Oct, to confirm): a create-only reporter (injuries.report, granted to support workers) can
 * report a workplace injury — and cannot open the register, the record, or any update, review or recovery work.
 */
class InjuryCreateOnlyReporterTest extends WorkplaceInjurySourceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_create_only_reporter_records_an_injury_and_reads_nothing_back(): void
    {
        $reporter = $this->staff(['injuries.report']);
        $this->actingAs($reporter)->get(route('health-safety.injuries.create'))->assertOk();

        $payload = $this->createPayload();
        $response = $this->postJson(route('health-safety.injuries.store'), $payload)->assertOk()
            ->assertJsonPath('injury_confirmation.accepted', true)->assertJsonPath('injury_href', null)
            ->assertJsonMissingPath('detail');
        $id = $response->json('injury_confirmation.injury_id');

        $this->getJson(route('health-safety.injuries.index'))->assertForbidden();
        $this->getJson(route('health-safety.injuries.show', $id))->assertForbidden();
        $this->postJson(route('health-safety.injuries.status', $id), [])->assertForbidden();
        $this->postJson(route('health-safety.injuries.reviews', $id), [])->assertForbidden();
        $this->postJson(route('health-safety.injuries.rtw-plans.store', $id), [])->assertForbidden();
    }

    public function test_without_the_report_key_the_form_stays_closed(): void
    {
        $this->actingAs($this->staff([]))->get(route('health-safety.injuries.create'))->assertForbidden();
        $this->postJson(route('health-safety.injuries.store'), $this->createPayload())->assertForbidden();
    }

}
