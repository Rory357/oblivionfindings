<?php

namespace Tests\Feature\HealthSafety;

/**
 * MASTER for Stephan (30 Oct, to confirm): a create-only reporter (restraints.report, granted to support workers) can
 * open the restraint report and save it — and nothing else: no register, no record, no review, no evidence afterwards,
 * no behaviour support plans.
 */
class RestraintCreateOnlyReporterTest extends RestraintEventCommandTestCase
{
    public function test_a_create_only_reporter_records_a_restraint_and_reads_nothing_back(): void
    {
        $reporter = $this->staff(['restraints.report', 'clients.viewAny']);
        // The form opens ready to record (found in the 30 Oct user test: it said "You can't make this change any more").
        $this->actingAs($reporter)->getJson(route('health-safety.restraints.report'))->assertOk()
            ->assertJsonPath('can.capture', true)->assertJsonPath('can.create_plan', false);

        $response = $this->postJson(route('health-safety.restraints.events.store'), $this->capture());
        $response->assertOk()->assertJsonPath('status', 'accepted')->assertJsonPath('event_href', null)
            ->assertJsonMissingPath('restraint_confirmation.restraint_description');
        $id = $response->json('restraint_confirmation.event_id');
        $this->assertIsInt($id);

        // Capture never confers read, review, evidence or plan authority.
        $this->getJson(route('health-safety.restraints.index'))->assertForbidden();
        $this->getJson(route('health-safety.restraints.events.show', $id))->assertForbidden();
        $this->getJson(route('health-safety.restraints.events.review-workspace', $id))->assertForbidden();
        $this->putJson(route('health-safety.restraints.events.update', $id), [])->assertForbidden();
        $this->postJson(route('health-safety.restraints.events.attachments.store', $id), [])->assertForbidden();
        $this->postJson(route('health-safety.restraints.plans.store'), [])->assertForbidden();
        $this->getJson(route('health-safety.restraints.report', ['kind' => 'plan']))->assertForbidden();
    }

    public function test_without_the_report_key_the_form_stays_closed(): void
    {
        $this->actingAs($this->staff(['clients.viewAny']))->getJson(route('health-safety.restraints.report'))->assertForbidden();
        $this->postJson(route('health-safety.restraints.events.store'), $this->capture())->assertForbidden();
    }
}
