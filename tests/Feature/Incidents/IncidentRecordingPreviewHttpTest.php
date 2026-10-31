<?php

namespace Tests\Feature\Incidents;

use App\Models\{Client, Site};
use Illuminate\Testing\TestResponse;

/** Actual mounted detail/current actor/Inertia partial route and real source-owner proof; ALL UNRUN. */
final class IncidentRecordingPreviewHttpTest extends IncidentRecordingCommandTestCase
{
    private function partial(int $id): TestResponse
    {
        return $this->get('/incidents/'.$id, ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'incidents/show',
            'X-Inertia-Partial-Data' => 'auth']);
    }

    public function test_partial_detail_always_contains_current_actor_preview_and_evidence_with_private_cache(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $key) { $this->allow($this->manager, $key); }
        $response = $this->partial($f['incident']->id)->assertOk()
            ->assertJsonPath('props.viewer_id', $this->manager->id)
            ->assertJsonPath('props.detail.recording_preview.actor_id', $this->manager->id)
            ->assertJsonPath('props.detail.recording_preview.incident_id', $f['incident']->id)
            ->assertJsonPath('props.detail.recording_preview.permissions.can_record', true)
            ->assertJsonPath('props.detail.recording_preview.sources.items.0.camera.id', $f['camera']->id)
            ->assertJsonPath('props.detail.recording_evidence.incident_evidence_version', 1);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $preview = $response->json('props.detail.recording_preview');
        $this->assertArrayNotHasKey('policy_snapshot', $preview['policy']);
        $this->assertArrayNotHasKey('configuration', $preview['sources']['items'][0]);
        $this->assertArrayNotHasKey('provider', $preview['sources']['items'][0]);
    }

    public function test_next_actor_partial_cannot_reuse_previous_actor_choices_or_operation_flags(): void
    {
        $f = $this->fixture();
        foreach (['incidents.update', 'incidents.evidence.window.record', 'incidents.evidence.attach'] as $key) { $this->allow($this->manager, $key); }
        $this->partial($f['incident']->id)->assertOk()->assertJsonPath('props.detail.recording_preview.permissions.can_record', true);
        // Cover has actual current incident read access but no reconstruction/Devices operation grant.
        $this->actingAs($this->cover);
        $response = $this->partial($f['incident']->id)->assertOk()
            ->assertJsonPath('props.viewer_id', $this->cover->id)
            ->assertJsonPath('props.detail.recording_preview.actor_id', $this->cover->id)
            ->assertJsonPath('props.detail.recording_preview.permissions.can_record', false)
            ->assertJsonPath('props.detail.recording_preview.permissions.can_attach', false)
            ->assertJsonPath('props.detail.recording_preview.policy.state', 'unavailable')
            ->assertJsonPath('props.detail.recording_preview.sources.items', []);
        $this->assertStringNotContainsString($f['camera']->name, $response->getContent());
        $this->assertStringNotContainsString($f['recorder']->name, $response->getContent());
    }

    public function test_current_read_revocation_and_out_of_scope_detail_do_not_publish_preview_or_private_text(): void
    {
        $f = $this->fixture();
        $this->allow($this->manager, 'incidents.viewAny', false); $this->allow($this->manager, 'incidents.viewAssigned', false);
        $response = $this->partial($f['incident']->id)->assertForbidden();
        $this->assertStringNotContainsString($f['camera']->name, $response->getContent());
        $this->assertStringNotContainsString('recording_preview', $response->getContent());
        $this->allow($this->manager, 'incidents.viewAny');
        $other = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $other->id]);
        $private = 'PRIVATE_OUTSIDE_APPROVED_SITE_PREVIEW';
        $incident = $this->incident(['site_id' => $other->id, 'client_id' => $client->id, 'description' => $private]);
        $response = $this->partial($incident->id)->assertNotFound();
        $this->assertStringNotContainsString($private, $response->getContent());
        $this->assertStringNotContainsString('recording_preview', $response->getContent());
    }
}
