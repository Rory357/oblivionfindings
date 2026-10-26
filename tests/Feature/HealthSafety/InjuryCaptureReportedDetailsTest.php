<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Permission;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\InjuryWorkspaceService;

/** Accepted new reports remain separate from enduring medical read or medical-edit authority. */
class InjuryCaptureReportedDetailsTest extends WorkplaceInjurySourceTestCase
{
    public function test_capture_only_actor_records_provided_new_private_facts_and_receives_only_minimal_acceptance(): void
    {
        $options = app(InjuryWorkspaceService::class)->captureOptions($this->capturer, $this->site->id);
        $this->assertTrue($options['can']['create']);
        $this->assertTrue($options['can']['record_reported_details']);
        $this->assertSame('restricted', $options['medical_access']);
        $payload = $this->createPayload(['notes' => 'New provided reported note', 'immediate_treatment' => 'New provided treatment',
            'medical_treatment_type' => 'gp_visit', 'acc_claim_number' => 'NEW-PROVIDED-CLAIM']);
        $receipt = $this->source()->create($this->capturer, $payload);
        $this->assertTrue($receipt['accepted']);
        $this->assertSame($this->capturer->id, $receipt['actor_id']);
        $this->assertSame('create', $receipt['command']);
        $this->assertStringNotContainsString('New provided', json_encode($receipt));
        $this->assertStringNotContainsString('NEW-PROVIDED-CLAIM', json_encode($receipt));
        foreach (['notes', 'immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'content', 'actor_snapshot'] as $key) {
            $this->assertArrayNotHasKey($key, $receipt);
        }
        $row = WorkplaceInjury::findOrFail($receipt['injury_id']);
        $this->assertSame('New provided reported note', $row->notes);
        $this->assertSame('New provided treatment', $row->immediate_treatment);
        $this->assertSame('gp_visit', $row->medical_treatment_type);
        $this->assertSame('NEW-PROVIDED-CLAIM', $row->acc_claim_number);
        $this->denial(fn () => app(InjuryWorkspaceService::class)->detail($this->capturer, $row->id));
        $this->denial(fn () => $this->source()->update($row, $this->capturer, $this->command($row, ['notes' => 'Forbidden enduring edit'])));
        $recovered = $this->source()->confirmationForKey($this->capturer, strtoupper($payload['idempotency_key']));
        $this->assertTrue($recovered['accepted']);
        $this->assertStringNotContainsString('New provided', json_encode($recovered));
        $this->assertStringNotContainsString('NEW-PROVIDED-CLAIM', json_encode($recovered));
        $this->assertDatabaseCount('workplace_injury_command_receipts', 1);
    }

    public function test_source_acceptance_still_rechecks_current_create_grant_site_and_worker_after_options_are_obtained(): void
    {
        $service = app(InjuryWorkspaceService::class);
        $this->assertTrue($service->captureOptions($this->capturer, $this->site->id)['can']['record_reported_details']);
        $this->revoke($this->capturer, 'hazards.create');
        $this->denial(fn () => $this->source()->create($this->capturer, $this->createPayload()));
        $this->capturer->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'hazards.create')->sole()->id => ['allowed' => true],
        ]);
        $this->assertTrue($service->captureOptions($this->capturer, $this->site->id)['can']['record_reported_details']);
        $other = Site::factory()->create(['is_active' => true]);
        $this->capturer->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        // A Site outside the capturer's current Sites is out of scope (404); a missing grant is 403.
        $this->denial(fn () => $this->source()->create($this->capturer, $this->createPayload()), 404);
        $this->capturer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->denial(fn () => $this->source()->create($this->capturer, $this->createPayload()));
        $this->assertDatabaseCount('workplace_injuries', 0);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 0);
    }

    public function test_capture_capability_does_not_relax_existing_medical_edit_or_accept_a_self_asserted_transport_grant(): void
    {
        $row = $this->injury();
        $this->denial(fn () => $this->source()->update($row, $this->factual, $this->command($row, ['notes' => 'Forbidden private edit'])));
        $receipt = $this->source()->update($row, $this->manager, $this->command($row, ['notes' => 'Authorised current medical edit']));
        $this->assertTrue($receipt['accepted']);
        $this->assertSame('Authorised current medical edit', $row->fresh()->notes);
        $this->validation(fn () => $this->source()->create($this->capturer,
            $this->createPayload(['record_reported_details' => true])), 'command');
        $this->assertDatabaseCount('workplace_injuries', 1);
        $this->assertDatabaseCount('workplace_injury_command_receipts', 2);
    }
}
