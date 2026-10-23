<?php

namespace Tests\Feature\Incidents;

use App\Domain\SecurityDevices\Cctv\Models\CctvRecordingReference;
use App\Domain\SecurityDevices\Cctv\Services\{CctvContract, CctvRecordingReferences};
use App\Models\{ClientIncident, IncidentEvidenceAggregate, IncidentEvidenceWindowCommandReceipt, IncidentRecordingAttachment, IncidentRecordingCommandReceipt};
use App\Services\Incidents\IncidentRecordingCommandService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Native HTTP routes/middleware + actual command service, policy, source authority and database. ALL UNRUN. */
final class IncidentRecordingCommandHttpTest extends IncidentRecordingCommandTestCase
{
    public function test_window_http_new_replay_recovery_conflict_and_current_source_denial(): void
    {
        $f = $this->fixture();
        $incident = $this->incident(['type' => 'fall', 'source' => 'manual', 'occurred_at' => '2026-10-12 02:00:00.123456']);
        foreach ([IncidentRecordingCommandService::WINDOW_PERMISSION, 'incidents.update'] as $key) { $this->allow($this->manager, $key); }
        $body = ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(),
            'expected_incident_evidence_version' => 0, 'expected_policy_revision' => $f['window']['rule_revision'],
            'requested_start_at' => '2026-10-12T01:59:00Z', 'requested_end_at' => '2026-10-12T02:01:00Z',
            'approved_sources' => $f['window']['approved_sources'], 'reason' => 'Record exact context for this incident.'];
        $url = '/incidents/'.$incident->id.'/evidence-windows';
        $first = $this->postJson($url, $body); $this->privateJson($first, 201);
        $original = $first->json();
        ClientIncident::withoutEvents(fn () => $incident->update(['status' => 'closed']));
        $replay = $this->postJson($url, $body); $this->privateJson($replay, 200); $this->assertSame($original, $replay->json());
        $recover = '/incidents/'.$incident->id.'/evidence-window-commands/'.$body['idempotency_key'].'?expected_actor_id='.$this->manager->id;
        $receipt = $this->getJson($recover); $this->privateJson($receipt, 200); $this->assertSame($original, $receipt->json());
        $conflict = $this->postJson($url, [...$body, 'reason' => 'A changed payload must never become a replay.']);
        $this->privateJson($conflict, 409); $conflict->assertExactJson(['code' => 'context_changed']);
        $this->assertSame(1, IncidentEvidenceWindowCommandReceipt::where('incident_id', $incident->id)->count());
        $this->allow($this->manager, 'securityDevices.viewAny', false);
        $this->privateJson($this->getJson($recover), 404);
    }

    public function test_attachment_http_new_replay_and_actor_private_recovery(): void
    {
        $f = $this->fixture(1);
        foreach ([IncidentRecordingCommandService::ATTACH_PERMISSION, 'incidents.update'] as $key) { $this->allow($this->manager, $key); }
        // A distinct canonical Devices receipt, using the reviewed fixture's exact source metadata shape.
        $existing = CctvRecordingReference::where('uuid', $f['attachments'][0]->reference_uuid)->firstOrFail();
        $uuid = (string) Str::uuid(); $metadata = $existing->metadata;
        $metadata['reference_uuid'] = $uuid; $metadata['reader_href'] = '/security-devices/cctv/recording-references/'.$uuid;
        $copy = $existing->replicate(); $copy->uuid = $uuid; $copy->request_key = (string) Str::uuid();
        $copy->metadata = $metadata; $copy->metadata_hash = CctvContract::hash($metadata); $copy->saveOrFail();
        $body = ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(),
            'expected_incident_evidence_version' => 2, 'evidence_window_revision' => 1, 'reference_uuid' => $uuid,
            'purpose' => 'Review this recording reference for the incident.'];
        $url = '/incidents/'.$f['incident']->id.'/recording-attachments';
        $first = $this->postJson($url, $body); $this->privateJson($first, 201);
        $replay = $this->postJson($url, $body); $this->privateJson($replay, 200); $this->assertSame($first->json(), $replay->json());
        $recover = '/incidents/'.$f['incident']->id.'/recording-attachment-commands/'.$body['idempotency_key'].'?expected_actor_id=';
        $this->privateJson($this->getJson($recover.$this->reporter->id), 403);
        $receipt = $this->getJson($recover.$this->manager->id); $this->privateJson($receipt, 200);
        $this->assertSame($first->json(), $receipt->json());
        $this->assertSame(2, IncidentRecordingAttachment::where('incident_id', $f['incident']->id)->count());
        $this->allow($this->manager, CctvRecordingReferences::VIEW, false);
        $this->privateJson($this->getJson($recover.$this->manager->id), 404);
    }

    public function test_raw_json_and_query_rejection_cannot_create_a_receipt_or_flash_private_input(): void
    {
        $f = $this->fixture(); $id = $f['incident']->id;
        $url = '/incidents/'.$id.'/recording-attachments';
        $private = 'PRIVATE_PURPOSE_MUST_NOT_APPEAR';
        foreach (['{', '[]', '{"purpose":"'.$private.'","purpose":"second"}', '{"approved_sources":[{"camera_id":1,"camera_id":2}]}'] as $raw) {
            $response = $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'text/html'], $raw);
            $this->privateJson($response, 400); $response->assertDontSee($private)->assertSessionMissing('_old_input');
        }
        $this->privateJson($this->post($url, ['purpose' => $private]), 400);
        $this->privateJson($this->postJson($url.'?unexpected=1', []), 400);
        $key = (string) Str::uuid(); $recover = '/incidents/'.$id.'/recording-attachment-commands/'.$key;
        foreach (['', '?expected_actor_id=01', '?expected_actor_id[]=1', '?expected_actor_id=1&expected_actor_id=2', '?expected_actor_id=1&extra=1'] as $query) {
            $this->privateJson($this->get($recover.$query), 400);
        }
        $this->privateJson($this->postJson('/incidents/0/recording-attachments', []), 404);
        $this->assertSame(0, IncidentRecordingCommandReceipt::where('incident_id', $id)->count());
        $this->assertSame(1, IncidentEvidenceAggregate::findOrFail($id)->incident_evidence_version);
    }

    public function test_both_actual_command_protocols_reject_missing_wrong_type_and_unknown_fields_as_400(): void
    {
        $f = $this->fixture(); $id = $f['incident']->id;
        foreach ([IncidentRecordingCommandService::ATTACH_PERMISSION, IncidentRecordingCommandService::WINDOW_PERMISSION, 'incidents.update'] as $key) {
            $this->allow($this->manager, $key);
        }
        $base = ['expected_actor_id' => $this->manager->id, 'idempotency_key' => (string) Str::uuid(), 'expected_incident_evidence_version' => 1];
        $commands = [
            'recording-attachments' => [...$base, 'evidence_window_revision' => 1, 'reference_uuid' => (string) Str::uuid(), 'purpose' => 'Private invalid command purpose.'],
            'evidence-windows' => [...$base, 'expected_policy_revision' => $f['window']['rule_revision'],
                'requested_start_at' => '2026-10-12T01:59:00Z', 'requested_end_at' => '2026-10-12T02:01:00Z',
                'approved_sources' => $f['window']['approved_sources'], 'reason' => 'Private invalid command reason.'],
        ];
        foreach ($commands as $path => $body) {
            $missing = $body; unset($missing['expected_actor_id']);
            foreach ([$missing, [...$body, 'expected_actor_id' => (string) $this->manager->id], [...$body, 'unexpected' => 'PRIVATE_UNSUPPORTED_VALUE']] as $invalid) {
                $response = $this->postJson('/incidents/'.$id.'/'.$path, $invalid);
                $this->privateJson($response, 400); $response->assertExactJson(['code' => 'invalid_request']);
                $response->assertSessionMissing('_old_input');
            }
        }
        $this->assertSame(0, IncidentRecordingCommandReceipt::where('incident_id', $id)->count());
        $this->assertSame(0, IncidentEvidenceWindowCommandReceipt::where('incident_id', $id)->count());
        $this->assertSame(1, IncidentEvidenceAggregate::findOrFail($id)->incident_evidence_version);
    }

    public function test_operation_permission_and_auth_failures_are_private_json_even_without_json_accept(): void
    {
        $f = $this->fixture(1); $id = $f['incident']->id;
        $receipt = IncidentRecordingCommandReceipt::where('incident_id', $id)->firstOrFail();
        $url = '/incidents/'.$id.'/recording-attachment-commands/'.$receipt->idempotency_key.'?expected_actor_id='.$this->manager->id;
        $this->allow($this->manager, IncidentRecordingCommandService::ATTACH_PERMISSION, false);
        $this->privateJson($this->get($url), 403);
        auth()->logout();
        $this->privateJson($this->get($url), 403);
    }

    public function test_405_preserves_allow_while_returning_only_private_generic_json(): void
    {
        $f = $this->fixture();
        foreach (['recording-attachments', 'evidence-windows'] as $path) {
            $response = $this->get('/incidents/'.$f['incident']->id.'/'.$path);
            $this->privateJson($response, 405);
            $response->assertHeader('Allow', 'POST')->assertExactJson(['code' => 'method_not_allowed']);
            $response->assertHeaderMissing('Location');
        }
    }

    private function privateJson(TestResponse $response, int $status): void
    {
        $response->assertStatus($status)->assertHeader('Content-Type', 'application/json')->assertHeader('X-Content-Type-Options', 'nosniff');
        $cache = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache); $this->assertStringContainsString('private', $cache);
    }
}
