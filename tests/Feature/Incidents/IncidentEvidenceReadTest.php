<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\Incidents\IncidentEvidenceReadService;
use App\Services\Incidents\IncidentIndependencePolicySettings as Policy;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Actual private Storage streams + canonical source/RBAC queries. Whole native/Storage suite UNRUN. */
final class IncidentEvidenceReadTest extends IncidentIndependenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp(); Storage::fake('private'); Storage::fake('public');
    }

    private function receiver(User $actor): Request
    {
        $this->actingAs($actor);
        $request = Request::create('/incidents', 'GET');
        $request->setUserResolver(fn () => auth()->user());
        app()->instance('request', $request);
        return $request;
    }

    private function file(ClientIncident $root, array $changes = [], string $bytes = 'Actual private incident evidence'): ClientIncidentAttachment
    {
        $values = ['incident_id' => $root->id, 'uploaded_by' => $this->reporter->id, 'disk' => 'private',
            'path' => 'incident_attachments/'.Str::uuid().'.txt', 'original_name' => 'private-evidence.txt',
            'mime' => 'text/plain', 'mime_type' => 'text/plain', 'size' => strlen($bytes), 'portal_visible' => false, ...$changes];
        Storage::disk($values['disk'] ?: 'private')->put($values['path'], $bytes);
        return ClientIncidentAttachment::withoutEvents(fn () => ClientIncidentAttachment::create($values));
    }

    private function response(ClientIncident $root, ClientIncidentAttachment $file, ?User $actor = null): StreamedResponse
    {
        return app(IncidentEvidenceReadService::class)->download($this->receiver($actor ?? $this->manager), $root->id, $file->id);
    }

    private function bytes(StreamedResponse $response): string
    {
        ob_start();
        try { $response->sendContent(); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    private function refuses(callable $operation, int $status): void
    {
        ob_start();
        try { $operation(); $this->fail('The actual source/stream operation must refuse this request.'); }
        catch (HttpExceptionInterface $error) { $this->assertSame($status, $error->getStatusCode()); $this->assertSame('', ob_get_contents()); }
        finally { ob_end_clean(); }
    }

    public function test_healthy_private_bytes_and_existing_hardened_disposition_headers_are_preserved(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $this->assertSame("default-src 'none'; sandbox; frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('Actual private incident evidence', $this->bytes($response));
    }

    public function test_underlying_actual_storage_read_stream_runs_inside_source_transaction(): void
    {
        $root = $this->incident(); $file = $this->file($root); $fake = Storage::disk('private'); $baseline = DB::transactionLevel();
        $adapter = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter {
            public int $baseline = 0;
            public bool $readInsideCurrentTransaction = false;
            public function readStream($path)
            {
                $this->readInsideCurrentTransaction = DB::transactionLevel() > $this->baseline;
                if (! $this->readInsideCurrentTransaction) { throw new \LogicException('Bytes escaped the source transaction.'); }
                return parent::readStream($path);
            }
        };
        $adapter->baseline = $baseline; Storage::set('private', $adapter);
        $response = $this->response($root, $file);
        $this->assertSame($baseline, DB::transactionLevel());
        $this->assertSame('Actual private incident evidence', $this->bytes($response));
        $this->assertTrue($adapter->readInsideCurrentTransaction); $this->assertSame($baseline, DB::transactionLevel());
    }

    public function test_foreign_parent_attachment_is_denied_before_path_or_filename_hydration(): void
    {
        $root = $this->incident(); $other = $this->incident(); $file = $this->file($other, ['original_name' => 'FOREIGN-PRIVATE-NAME.txt']);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->refuses(fn () => app(IncidentEvidenceReadService::class)->download($this->receiver($this->manager), $root->id, $file->id), 404);
            $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'client_incident_attachments'));
            $this->assertNotEmpty($queries);
            foreach ($queries as $query) { $this->assertStringContainsString('`incident_id` = ?', $query['query']); $this->assertContains($root->id, $query['bindings']); }
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_named_strong_source_denial_does_not_query_attachment_metadata(): void
    {
        $receipt = $this->accept($this->command()); $root = ClientIncident::findOrFail($receipt['incident_id']); $file = $this->file($root);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->refuses(fn () => $this->response($root, $file, $this->lead), 404);
            $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'client_incident_attachments'))));
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }

    public function test_assignment_only_does_not_remove_ordinary_authorized_attachment_read(): void
    {
        app(Policy::class)->save($this->manager, ['mode' => 'assignment_only', 'expected_policy_revision' => 0,
            'request_uuid' => (string) Str::uuid(), 'reason' => 'Future reports.']);
        $receipt = $this->accept($this->command(['expected_policy_revision' => 1])); $root = ClientIncident::findOrFail($receipt['incident_id']); $file = $this->file($root);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file, $this->lead)));
        $this->refuses(fn () => $this->response($root, $file, $this->reporter), 403);
    }

    public function test_warmed_read_permission_revocation_between_response_and_stream_precedes_bytes(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $this->refuses(fn () => $response->sendContent(), 403);
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_receiver_change_after_response_does_not_deliver_another_actors_admitted_bytes(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $this->receiver($this->cover); $this->refuses(fn () => $response->sendContent(), 403);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file, $this->cover)));
    }

    public function test_current_staff_site_withdrawal_then_restoration_is_rechecked_at_stream(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $foreign = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $foreign->id]);
        $this->refuses(fn () => $response->sendContent(), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_inactive_hr_profile_cannot_keep_prepared_stream_authority(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->refuses(fn () => $response->sendContent(), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_hr_private_permission_withdrawal_prevents_prepared_stream(): void
    {
        $root = $this->incident(['is_hr_confidential' => true]); $file = $this->file($root); $this->allow($this->manager, 'hr.cases.view');
        $response = $this->response($root, $file); $this->allow($this->manager, 'hr.cases.view', false);
        $this->refuses(fn () => $response->sendContent(), 404);
        $this->allow($this->manager, 'hr.cases.view');
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_approved_but_changed_original_parent_site_snapshot_is_not_the_prepared_source(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $other = Site::factory()->create(['is_active' => true]); $this->allow($this->manager, 'healthSafety.viewAllSites');
        DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $other->id]);
        $this->refuses(fn () => $response->sendContent(), 404);
        DB::table('client_incidents')->where('id', $root->id)->update(['site_id' => $this->site->id]);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_actual_new_strong_intake_acceptance_withdraws_prepared_named_reader_before_file_metadata(): void
    {
        $command = $this->command(['intent' => 'draft']);
        $root = $this->incident(['client_id' => $this->client->id, 'site_id' => $this->site->id, 'shift_id' => null,
            'source' => 'manual', 'report_request_uuid' => $command['report_request_uuid'], 'status' => 'draft', 'submitted_at' => null]);
        $file = $this->file($root); $response = $this->response($root, $file, $this->lead);
        $accepted = $this->accept($command); $this->assertSame($root->id, $accepted['incident_id']);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->refuses(fn () => $response->sendContent(), 404);
            $this->assertSame([], array_values(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'client_incident_attachments'))));
        } finally { DB::disableQueryLog(); DB::flushQueryLog(); }
    }
    public function test_damaged_independence_evidence_cannot_keep_a_prepared_stream(): void
    {
        $receipt = $this->accept($this->command()); $root = ClientIncident::findOrFail($receipt['incident_id']); $file = $this->file($root);
        $response = $this->response($root, $file);
        DB::table('incident_independent_handling_declarations')->where('id', $receipt['declaration_id'])->update(['handling' => 'false']);
        $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_attachment_reparent_between_response_and_stream_is_denied_before_bytes(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file); $other = $this->incident();
        DB::table('client_incident_attachments')->where('id', $file->id)->update(['incident_id' => $other->id]);
        $this->refuses(fn () => $response->sendContent(), 404);
        DB::table('client_incident_attachments')->where('id', $file->id)->update(['incident_id' => $root->id]);
        $this->assertSame('Actual private incident evidence', $this->bytes($this->response($root, $file)));
    }

    public function test_changed_filename_is_not_emitted_with_prepared_disposition(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        DB::table('client_incident_attachments')->where('id', $file->id)->update(['original_name' => 'changed-private-name.txt']);
        $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_changed_path_or_mime_is_not_certified_by_old_descriptor(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        Storage::disk('private')->put('incident_attachments/replacement.txt', 'Different private bytes');
        DB::table('client_incident_attachments')->where('id', $file->id)->update(['path' => 'incident_attachments/replacement.txt', 'mime' => 'application/pdf']);
        $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_public_legacy_disk_is_not_a_private_evidence_delivery_grant(): void
    {
        $root = $this->incident(); $file = $this->file($root, ['disk' => 'public']);
        $this->refuses(fn () => $this->response($root, $file), 404);
        $this->assertTrue(Storage::disk('public')->exists($file->path));
    }

    public function test_unsupported_or_unsafe_retained_paths_never_trigger_storage_delivery(): void
    {
        $root = $this->incident(); $file = $this->file($root);
        foreach (['../other.txt', '/absolute.txt', 'incident_attachments\\other.txt', 'C:other.txt', "bad\0path", ''] as $path) {
            DB::table('client_incident_attachments')->where('id', $file->id)->update(['path' => $path]);
            $this->refuses(fn () => $this->response($root, $file), 404);
        }
    }
    public function test_changed_disk_is_denied_before_any_public_bytes(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        Storage::disk('public')->put($file->path, 'Unrelated public bytes');
        DB::table('client_incident_attachments')->where('id', $file->id)->update(['disk' => 'public']);
        $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_missing_bytes_after_response_are_unavailable_not_an_empty_successful_download(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        Storage::disk('private')->delete($file->path); $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_changed_observed_length_does_not_emit_bytes_behind_original_content_length(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        Storage::disk('private')->put($file->path, 'Changed longer actual private bytes with different size');
        $this->refuses(fn () => $response->sendContent(), 404);
    }

    public function test_stream_does_not_claim_digest_or_original_byte_attribution_it_cannot_prove(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        $replacement = str_repeat('X', strlen('Actual private incident evidence'));
        Storage::disk('private')->put($file->path, $replacement);
        $this->assertSame($replacement, $this->bytes($response));
        $this->assertNull($response->headers->get('Digest')); $this->assertNull($response->headers->get('ETag'));
    }

    public function test_root_deletion_and_file_deletion_cannot_keep_prepared_authority(): void
    {
        $root = $this->incident(); $file = $this->file($root); $response = $this->response($root, $file);
        DB::table('client_incident_attachments')->where('id', $file->id)->delete();
        $this->refuses(fn () => $response->sendContent(), 404);
        $other = $this->incident(); $otherFile = $this->file($other); $otherResponse = $this->response($other, $otherFile);
        DB::table('client_incidents')->where('id', $other->id)->delete(); $this->refuses(fn () => $otherResponse->sendContent(), 404);
    }

    public function test_actual_http_download_stream_is_guarded_and_hardened_after_root_mount(): void
    {
        $root = $this->incident(); $file = $this->file($root); $this->actingAs($this->manager);
        $response = $this->get('/incidents/'.$root->id.'/attachments/'.$file->id.'/download')->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('Actual private incident evidence', $response->streamedContent());
    }

    public function test_actual_http_raw_foreign_file_and_named_source_are_concealed_without_middleware_suppression(): void
    {
        $root = $this->incident(); $other = $this->incident(); $foreign = $this->file($other); $this->actingAs($this->manager);
        $this->get('/incidents/'.$root->id.'/attachments/'.$foreign->id.'/download')->assertNotFound();
        $receipt = $this->accept($this->command()); $blocked = ClientIncident::findOrFail($receipt['incident_id']); $file = $this->file($blocked);
        $this->actingAs($this->lead)->get('/incidents/'.$blocked->id.'/attachments/'.$file->id.'/download')->assertNotFound();
    }

    public function test_actual_http_loaded_false_read_restore_uses_current_same_grant_attachment_route(): void
    {
        $root = $this->incident(); $file = $this->file($root);
        foreach (['incidents.viewAny', 'incidents.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        $warm = $this->manager->fresh()->load(['roles.permissions', 'permissionOverrides']); $this->actingAs($warm);
        $url = '/incidents/'.$root->id.'/attachments/'.$file->id.'/download';
        $this->get($url)->assertForbidden(); $this->allow($warm, 'incidents.viewAny'); $this->assertFalse($warm->canDo('incidents.viewAny'));
        $this->assertSame('Actual private incident evidence', $this->get($url)->assertOk()->streamedContent());
    }

    public function test_actual_http_raw_zero_or_overflow_identities_are_rejected_before_private_file_resolution(): void
    {
        $root = $this->incident(); $file = $this->file($root); $this->actingAs($this->manager);
        $this->get('/incidents/0/attachments/'.$file->id.'/download')->assertNotFound();
        $this->get('/incidents/'.$root->id.'/attachments/0/download')->assertNotFound();
        $this->get('/incidents/99999999999999999999999999/attachments/'.$file->id.'/download')->assertNotFound();
        $this->get('/incidents/'.$root->id.'/attachments/99999999999999999999999999/download')->assertNotFound();
    }
}
