<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\HsConsultation;
use App\Models\HsRepresentative;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Native route/stream candidates. These declarations are not protocol acceptance until the whole suite executes. */
class WorkerParticipationEvidenceReadTest extends RestraintEventCommandTestCase
{
    private HsCommittee $committee;
    private HsCommitteeMeeting $meeting;
    private HsConsultation $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland', 'app.timezone' => 'UTC']);
        Storage::fake('private');
        $this->actor = $this->staff(['hazards.view']);
        $this->committee = HsCommittee::create(['name' => 'Approved committee', 'site_id' => $this->site->id, 'established_at' => '2026-01-01', 'members' => [$this->actor->id]]);
        $this->meeting = HsCommitteeMeeting::create(['hs_committee_id' => $this->committee->id, 'scheduled_at' => '2026-10-11 00:30:00', 'status' => 'completed',
            'minutes_document_name' => 'Original minutes.txt', 'actions_due_count' => 3]);
        $this->meeting->update(['minutes_document_path' => 'health-safety/meetings/'.$this->meeting->id.'/minutes.txt']);
        $this->consultation = HsConsultation::create(['title' => '=Private consultation', 'consultation_type' => 'general', 'description' => 'Recorded source',
            'site_id' => $this->site->id, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11',
            'document_name' => 'Supporting document.txt', 'outcome_document_name' => 'Outcome document.txt']);
        $this->consultation->update(['document_path' => 'health-safety/consultations/'.$this->consultation->id.'/document.txt',
            'outcome_document_path' => 'health-safety/consultations/'.$this->consultation->id.'/outcome.txt']);
        HsRepresentative::create(['user_id' => $this->actor->id, 'site_id' => $this->site->id, 'work_group' => 'Real work group', 'election_method' => 'elected', 'elected_at' => '2026-01-01', 'training_days_completed' => 2]);
        foreach (['minutes', 'document', 'outcome'] as $name) { Storage::disk('private')->put($this->filePath($name), 'PRIVATE '.$name.' BYTES'); }
    }

    public static function attachments(): array { return [['minutes'], ['document'], ['outcome']]; }

    #[DataProvider('attachments')]
    public function test_native_download_preserves_private_headers_filename_mime_length_and_bytes(string $type): void
    {
        $response = $this->actingAs($this->actor)->get($this->url($type));
        $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox; frame-ancestors 'none'");
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame((string) strlen('PRIVATE '.$type.' BYTES'), $response->headers->get('Content-Length'));
        $this->assertSame('PRIVATE '.$type.' BYTES', $response->streamedContent());
    }

    #[DataProvider('attachments')]
    public function test_native_head_retains_get_file_headers_without_private_bytes(string $type): void
    {
        $observed = $this->observeDisk();
        $response = $this->actingAs($this->actor)->head($this->url($type));
        $response->assertOk()->assertHeader('Content-Length', (string) strlen('PRIVATE '.$type.' BYTES'));
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertSame('', $response->streamedContent());
        $this->assertSame([], array_values(array_filter($observed->reads, fn ($read) => $read[0] === 'bytes')));
    }

    public function test_native_minutes_fallback_filename_uses_original_basename(): void
    {
        DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_name' => null]);
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $this->assertStringContainsString('minutes.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('PRIVATE minutes BYTES', $response->streamedContent());
    }

    #[DataProvider('attachments')]
    public function test_original_direct_download_url_without_site_parameter_resolves_actual_admitted_source_site(string $type): void
    {
        $url = explode('?', $this->url($type))[0];
        $response = $this->actingAs($this->actor)->get($url)->assertOk();
        $this->assertSame('PRIVATE '.$type.' BYTES', $response->streamedContent());
    }

    public function test_explicit_site_cannot_replace_actual_download_source_site(): void
    {
        $remote = Site::factory()->create(['is_active' => true]);
        $this->actingAs($this->actor)->get(explode('?', $this->url('minutes'))[0].'?site_id='.$remote->id)->assertNotFound();
    }

    public function test_direct_download_original_root_stamp_does_not_freeze_an_unrelated_records_people_pointers(): void
    {
        $other = HsCommitteeMeeting::create(['hs_committee_id' => $this->committee->id, 'scheduled_at' => now()]);
        $response = $this->actingAs($this->actor)->get(explode('?', $this->url('minutes'))[0])->assertOk();
        DB::table('hs_committee_meetings')->where('id', $other->id)->update(['recorded_by' => $this->actor->id]);
        $this->assertSame('PRIVATE minutes BYTES', $response->streamedContent());
    }

    public function test_default_board_csv_preserves_all_approved_sites_and_all_three_sections(): void
    {
        $other = Site::factory()->create(['is_active' => true]);
        DB::table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['secondary_site_ids' => json_encode([$other->id], JSON_THROW_ON_ERROR)]);
        HsConsultation::create(['title' => 'SECOND APPROVED SITE', 'consultation_type' => 'general', 'description' => 'Recorded', 'site_id' => $other->id,
            'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11']);
        $csv = $this->actingAs($this->actor)->get('/health-safety/worker-participation/export')->assertOk()->streamedContent();
        foreach (['H&S Representatives', 'Committee Meetings', 'Worker Consultations', 'SECOND APPROVED SITE', 'Approved committee'] as $fact) { $this->assertStringContainsString($fact, $csv); }
        $selected = $this->actingAs($this->actor)->get($this->csvUrl())->assertOk()->streamedContent();
        $this->assertStringNotContainsString('SECOND APPROVED SITE', $selected);
    }

    public function test_foreign_meeting_and_consultation_ids_never_hydrate_private_descriptor(): void
    {
        $remote = Site::factory()->create(['is_active' => true]);
        $committee = HsCommittee::create(['name' => 'Denied committee', 'site_id' => $remote->id, 'established_at' => '2026-01-01']);
        $meeting = HsCommitteeMeeting::create(['hs_committee_id' => $committee->id, 'scheduled_at' => now(), 'minutes_document_path' => 'participation/minutes.txt']);
        $consultation = HsConsultation::create(['title' => 'Denied source', 'consultation_type' => 'general', 'description' => 'Private', 'site_id' => $remote->id,
            'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11', 'document_path' => 'participation/document.txt']);
        $dispatcher = Model::getEventDispatcher(); $hydrated = [];
        Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$hydrated): void { if (array_key_exists('minutes_document_path', $row->getAttributes())) { $hydrated[] = $row->id; } });
        HsConsultation::retrieved(function ($row) use (&$hydrated): void { if (array_key_exists('document_path', $row->getAttributes())) { $hydrated[] = $row->id; } });
        try {
            $this->actingAs($this->actor)->get('/health-safety/worker-participation/meetings/'.$meeting->id.'/minutes/download?site_id='.$this->site->id)->assertNotFound();
            $this->actingAs($this->actor)->get('/health-safety/worker-participation/consultations/'.$consultation->id.'/documents/document?site_id='.$this->site->id)->assertNotFound();
            $this->assertSame([], $hydrated);
        } finally { Model::setEventDispatcher($dispatcher); }
    }

    public static function continuationChanges(): array { return [['grant'], ['site'], ['path'], ['name'], ['committee_site'], ['timezone']]; }

    #[DataProvider('continuationChanges')]
    public function test_original_ticket_refuses_later_authority_descriptor_or_source_change_with_zero_bytes(string $change): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $this->change($change);
        $dispatcher = Model::getEventDispatcher(); $privateDescriptors = [];
        Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$privateDescriptors): void {
            if (array_key_exists('minutes_document_path', $row->getAttributes())) { $privateDescriptors[] = $row->id; }
        });
        try {
            $this->refusedWithoutBytes($response);
            if (in_array($change, ['grant', 'site', 'committee_site', 'timezone'], true)) { $this->assertSame([], $privateDescriptors); }
        } finally { Model::setEventDispatcher($dispatcher); }
        $this->restore($change);
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
    }

    public function test_native_head_after_preflight_withdrawal_sends_no_body_and_does_not_claim_callback_revalidation(): void
    {
        $observed = $this->observeDisk();
        $response = $this->actingAs($this->actor)->head($this->url('minutes'))->assertOk();
        $this->revoke($this->actor, 'hazards.view');
        // Symfony prepares HEAD with setContent(null), which skips the streaming callback.
        // HEAD's admission/descriptor proof is preflight only; emitted headers cannot be recalled.
        $this->assertSame('', $response->streamedContent());
        $this->assertSame([], array_values(array_filter($observed->reads, fn ($read) => $read[0] === 'bytes')));
    }

    public function test_private_path_traversal_is_denied_before_native_file_preparation(): void
    {
        DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_path' => '../outside.txt']);
        $this->actingAs($this->actor)->get($this->url('minutes'))->assertNotFound();
    }

    public function test_missing_private_file_and_unknown_consultation_document_kind_are_not_found(): void
    {
        Storage::disk('private')->delete($this->filePath('minutes'));
        $this->actingAs($this->actor)->get($this->url('minutes'))->assertNotFound();
        $this->actingAs($this->actor)->get('/health-safety/worker-participation/consultations/'.$this->consultation->id.'/documents/unknown?site_id='.$this->site->id)->assertNotFound();
    }

    public static function unownedPaths(): array { return [['foreign_root'], ['wrong_module'], ['traversal'], ['dot'], ['empty'], ['unproven_legacy']]; }

    #[DataProvider('unownedPaths')]
    public function test_record_path_cannot_serve_foreign_module_root_or_unproven_legacy_bytes_before_any_storage_read(string $case): void
    {
        $base = 'health-safety/meetings/'.$this->meeting->id.'/';
        $path = match ($case) {
            'foreign_root' => 'health-safety/meetings/'.$this->meeting->id.'1/private.txt',
            'wrong_module' => 'health-safety/injuries/'.$this->meeting->id.'/private.txt',
            'traversal' => $base.'../private.txt',
            'dot' => $base.'./private.txt',
            'empty' => $base.'/private.txt',
            'unproven_legacy' => 'minutes/recorded.pdf',
        };
        if (in_array($case, ['foreign_root', 'wrong_module', 'unproven_legacy'], true)) { Storage::disk('private')->put($path, 'FOREIGN PRIVATE BYTES'); }
        DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_path' => $path]);
        $observed = $this->observeDisk();
        $this->actingAs($this->actor)->get($this->url('minutes'))->assertNotFound();
        $this->assertSame([], $observed->reads);
        $this->restore('path');
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
        $this->assertContains(['bytes', $this->filePath('minutes')], $observed->reads);
    }

    public function test_final_proof_withholds_buffered_file_when_descriptor_changes_after_private_select(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $armed = true; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$armed): void {
            if ($armed && array_key_exists('minutes_document_path', $row->getAttributes())) {
                $armed = false;
                DB::table('hs_committee_meetings')->where('id', $row->id)->update(['minutes_document_name' => 'Changed after select.txt']);
            }
        });
        try { $this->refusedWithoutBytes($response); $this->assertFalse($armed); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_native_csv_retains_all_sections_positive_facts_site_boundary_and_formula_escaping_despite_focus(): void
    {
        $remote = Site::factory()->create(['is_active' => true]);
        HsConsultation::create(['title' => 'DENIED NEIGHBOUR CSV', 'consultation_type' => 'general', 'description' => 'Private', 'site_id' => $remote->id,
            'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11']);
        $response = $this->actingAs($this->actor)->get($this->csvUrl().'&tab=meetings&focus=minutes_missing')->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        foreach (['H&S Representatives', 'Committee Meetings', 'Worker Consultations', 'Real work group', 'Approved committee', "'=Private consultation", 'Actions outstanding', '2026-10-11 13:30'] as $fact) { $this->assertStringContainsString($fact, $csv); }
        $this->assertStringNotContainsString('DENIED NEIGHBOUR CSV', $csv);
    }

    public function test_final_proof_withholds_read_file_bytes_after_original_grant_withdraws_at_second_descriptor(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $descriptors = 0; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsCommitteeMeeting::retrieved(function ($row) use (&$descriptors): void {
            if (array_key_exists('minutes_document_path', $row->getAttributes()) && ++$descriptors === 2) {
                // The actual file has been read into the private buffer, but no output has occurred.
                $this->revoke($this->actor, 'hazards.view');
            }
        });
        try { $this->refusedWithoutBytes($response); $this->assertSame(2, $descriptors); }
        finally { Model::setEventDispatcher($dispatcher); }
        $this->restore('grant');
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
    }

    public function test_csv_original_ticket_withholds_all_sections_after_grant_withdrawal_and_recovers_fresh(): void
    {
        $response = $this->actingAs($this->actor)->get($this->csvUrl())->assertOk();
        $this->revoke($this->actor, 'hazards.view'); $this->refusedWithoutBytes($response); $this->restore('grant');
        $this->assertStringContainsString('Worker Consultations', $this->actingAs($this->actor)->get($this->csvUrl())->assertOk()->streamedContent());
    }

    public function test_csv_final_proof_withholds_earlier_body_facts_after_late_source_mutation(): void
    {
        $response = $this->actingAs($this->actor)->get($this->csvUrl())->assertOk();
        $armed = true; $dispatcher = Model::getEventDispatcher(); Model::setEventDispatcher(clone $dispatcher);
        HsConsultation::retrieved(function ($row) use (&$armed): void {
            if ($armed && array_key_exists('title', $row->getAttributes())) {
                $armed = false; DB::table('hs_consultations')->where('id', $row->id)->update(['title' => 'Changed after body']);
            }
        });
        try { $this->refusedWithoutBytes($response); $this->assertFalse($armed); }
        finally { Model::setEventDispatcher($dispatcher); }
    }

    public function test_consumed_native_continuation_callback_cannot_be_replayed(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $callback = $response->baseResponse->getCallback();
        ob_start(); try { $callback(); $this->assertSame('PRIVATE minutes BYTES', ob_get_contents()); } finally { ob_end_clean(); }
        $this->expectException(\LogicException::class); $callback();
    }

    public function test_request_actor_replacement_cannot_consume_original_private_ticket(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $live = request(); $resolver = $live->getUserResolver();
        $other = $this->staff(['hazards.view']);
        $live->setUserResolver(fn () => $other);
        try { $this->refusedWithoutBytes($response); }
        finally { $live->setUserResolver($resolver); }
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
    }

    public function test_same_actor_on_a_different_request_object_cannot_consume_original_private_ticket(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $original = request();
        $replacement = $original->duplicate();
        $replacement->setUserResolver($original->getUserResolver());
        app()->instance('request', $replacement);
        try { $this->refusedWithoutBytes($response); }
        finally { app()->instance('request', $original); }
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
    }

    public function test_native_stream_withholds_buffered_file_after_source_mutation_on_actual_stream_close(): void
    {
        $response = $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk();
        $observed = $this->observeDisk();
        $closed = false;
        $filterName = 'hs-participation-source-mutation';
        if (! in_array($filterName, stream_get_filters(), true)) {
            $this->assertTrue(stream_filter_register($filterName, ParticipationEvidenceSourceMutationFilter::class));
        }
        $observed->onStream = function ($stream) use ($filterName, &$closed): void {
            $filter = stream_filter_append($stream, $filterName, STREAM_FILTER_READ, function () use (&$closed): void {
                $closed = true;
                DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_name' => 'Changed at real stream close.txt']);
            });
            $this->assertIsResource($filter);
        };
        $this->refusedWithoutBytes($response);
        $this->assertTrue($closed);
        $this->assertContains(['bytes', $this->filePath('minutes')], $observed->reads);
        $observed->onStream = null;
        $this->restore('name');
        $this->assertSame('PRIVATE minutes BYTES', $this->actingAs($this->actor)->get($this->url('minutes'))->assertOk()->streamedContent());
    }

    public function test_consultation_cannot_point_at_another_consultations_private_document(): void
    {
        $other = HsConsultation::create(['title' => 'Other source', 'consultation_type' => 'general', 'description' => 'Private', 'site_id' => $this->site->id,
            'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11']);
        $path = 'health-safety/consultations/'.$other->id.'/other.txt';
        Storage::disk('private')->put($path, 'FOREIGN CONSULTATION BYTES');
        DB::table('hs_consultations')->where('id', $this->consultation->id)->update(['document_path' => $path]);
        $this->actingAs($this->actor)->get($this->url('document'))->assertNotFound();
        DB::table('hs_consultations')->where('id', $this->consultation->id)->update(['document_path' => $this->filePath('document')]);
        $this->assertSame('PRIVATE document BYTES', $this->actingAs($this->actor)->get($this->url('document'))->assertOk()->streamedContent());
    }

    public function test_missing_site_ownership_is_readable_only_with_explicit_wide_authority_and_csv_flags_it(): void
    {
        [$meeting, $consultation] = $this->missingOwnership();
        $minutes = '/health-safety/worker-participation/meetings/'.$meeting->id.'/minutes/download';
        $document = '/health-safety/worker-participation/consultations/'.$consultation->id.'/documents/document';
        $this->actingAs($this->actor)->get($minutes)->assertNotFound();
        $this->actingAs($this->actor)->get($document)->assertNotFound();
        $this->wide(true);
        $this->assertSame('MISSING SITE MINUTES', $this->actingAs($this->actor)->get($minutes)->assertOk()->streamedContent());
        $this->assertSame('MISSING SITE DOCUMENT', $this->actingAs($this->actor)->get($document)->assertOk()->streamedContent());
        $this->actingAs($this->actor)->get($minutes.'?site_id='.$this->site->id)->assertNotFound();
        $csv = $this->actingAs($this->actor)->get('/health-safety/worker-participation/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Missing ownership committee', $csv);
        $this->assertStringContainsString('Missing ownership consultation', $csv);
        $this->assertSame(3, substr_count($csv, 'Site ownership not confirmed'));
        $selected = $this->actingAs($this->actor)->get($this->csvUrl())->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Missing ownership', $selected);
        $this->assertStringNotContainsString('Site ownership not confirmed', $selected);
    }

    public function test_missing_ownership_download_ticket_cannot_survive_wide_grant_withdrawal(): void
    {
        [$meeting] = $this->missingOwnership(); $this->wide(true);
        $url = '/health-safety/worker-participation/meetings/'.$meeting->id.'/minutes/download';
        $response = $this->actingAs($this->actor)->get($url)->assertOk();
        $this->wide(false); $this->refusedWithoutBytes($response); $this->wide(true);
        $this->assertSame('MISSING SITE MINUTES', $this->actingAs($this->actor)->get($url)->assertOk()->streamedContent());
    }

    private function wide(bool $allowed): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching([\App\Models\Permission::where('key', 'healthSafety.viewAllSites')->sole()->id => ['allowed' => $allowed]]);
    }

    private function missingOwnership(): array
    {
        $committee = HsCommittee::create(['name' => 'Missing ownership committee', 'site_id' => null, 'established_at' => '2026-01-01']);
        $meeting = HsCommitteeMeeting::create(['hs_committee_id' => $committee->id, 'scheduled_at' => '2026-10-11 00:30:00']);
        $meeting->update(['minutes_document_path' => 'health-safety/meetings/'.$meeting->id.'/missing.txt']);
        $consultation = HsConsultation::create(['title' => 'Missing ownership consultation', 'consultation_type' => 'general', 'description' => 'Recorded',
            'site_id' => null, 'initiated_by' => $this->actor->id, 'consultation_date' => '2026-10-11']);
        $consultation->update(['document_path' => 'health-safety/consultations/'.$consultation->id.'/missing.txt']);
        HsRepresentative::create(['user_id' => $this->actor->id, 'site_id' => null, 'work_group' => 'Missing ownership representative', 'election_method' => 'elected', 'elected_at' => '2026-01-01']);
        Storage::disk('private')->put($meeting->minutes_document_path, 'MISSING SITE MINUTES');
        Storage::disk('private')->put($consultation->document_path, 'MISSING SITE DOCUMENT');
        return [$meeting, $consultation];
    }

    private function url(string $type): string
    {
        return '/health-safety/worker-participation/'.($type === 'minutes' ? 'meetings/'.$this->meeting->id.'/minutes/download' : 'consultations/'.$this->consultation->id.'/documents/'.$type).'?site_id='.$this->site->id;
    }
    private function csvUrl(): string { return '/health-safety/worker-participation/export?site_id='.$this->site->id; }
    private function refusedWithoutBytes($response): void
    {
        ob_start();
        try { $response->baseResponse->sendContent(); $this->fail('Changed original evidence must be refused.'); }
        catch (HttpException|\LogicException $error) { if ($error instanceof HttpException) { $this->assertContains($error->getStatusCode(), [403, 404]); } $this->assertSame('', ob_get_contents()); }
        finally { ob_end_clean(); }
    }
    private function change(string $change): void
    {
        match ($change) {
            'grant' => $this->revoke($this->actor, 'hazards.view'),
            'site' => DB::table('sites')->where('id', $this->site->id)->update(['archived' => true]),
            'path' => DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_path' => $this->filePath('document')]),
            'name' => DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_name' => 'Replacement.txt']),
            'committee_site' => DB::table('hs_committees')->where('id', $this->committee->id)->update(['site_id' => Site::factory()->create(['is_active' => true])->id]),
            'timezone' => config(['app.worker_timezone' => 'Pacific/Honolulu']),
        };
    }
    private function restore(string $change): void
    {
        $this->actor->permissionOverrides()->updateExistingPivot(\App\Models\Permission::where('key', 'hazards.view')->sole()->id, ['allowed' => true]);
        DB::table('sites')->where('id', $this->site->id)->update(['archived' => false]);
        DB::table('hs_committees')->where('id', $this->committee->id)->update(['site_id' => $this->site->id]);
        DB::table('hs_committee_meetings')->where('id', $this->meeting->id)->update(['minutes_document_path' => $this->filePath('minutes'), 'minutes_document_name' => 'Original minutes.txt']);
        config(['app.worker_timezone' => 'Pacific/Auckland']);
    }

    private function filePath(string $type): string
    {
        return 'health-safety/'.($type === 'minutes' ? 'meetings/'.$this->meeting->id : 'consultations/'.$this->consultation->id).'/'.$type.'.txt';
    }

    private function observeDisk(): \Illuminate\Filesystem\FilesystemAdapter
    {
        $disk = Storage::disk('private');
        // Observe the real fake-disk adapter; every operation delegates to its actual filesystem.
        $observed = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends \Illuminate\Filesystem\FilesystemAdapter {
            public array $reads = [];
            public ?\Closure $onStream = null;
            public function exists($path) { $this->reads[] = ['exists', $path]; return parent::exists($path); }
            public function mimeType($path) { $this->reads[] = ['mime', $path]; return parent::mimeType($path); }
            public function size($path) { $this->reads[] = ['size', $path]; return parent::size($path); }
            public function readStream($path) {
                $this->reads[] = ['bytes', $path];
                $stream = parent::readStream($path);
                if (is_resource($stream) && $this->onStream !== null) { ($this->onStream)($stream); }
                return $stream;
            }
        };
        Storage::set('private', $observed);
        return $observed;
    }
}

/** Test-only filter on the actual private-disk read stream; mutates the source when that stream closes. */
final class ParticipationEvidenceSourceMutationFilter extends \php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        if ($closing && $this->params instanceof \Closure) { ($this->params)(); }
        return PSFS_PASS_ON;
    }
}
