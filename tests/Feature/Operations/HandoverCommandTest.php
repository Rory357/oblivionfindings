<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Handovers\OperationsHandoverIntent;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OperationsHandoverWitness;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\CommittedDatabaseTestCase;

/**
 * Real route/model/root integration; runs only against the isolated MySQL test database.
 * The handover root needs a connection with no open transaction, so fixtures commit and
 * CommittedDatabaseTestCase restores every changed table after each test (DatabaseMigrations
 * would rebuild the whole schema per test, several minutes each).
 */
class HandoverCommandTest extends CommittedDatabaseTestCase
{
    private User $worker;
    private User $incoming;
    private User $other;
    private Shift $shift;
    private Shift $next;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $site = Site::factory()->create();
        $context = ServiceContext::factory()->create(['is_active' => true, 'type' => 'residential']);
        $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id]);
        foreach (['worker', 'incoming', 'other'] as $name) {
            $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
            $user->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
            $grants = Permission::query()->whereIn('key', ['handovers.create', 'shifts.update', 'shifts.viewAssigned'])->pluck('id');
            $user->permissionOverrides()->syncWithoutDetaching($grants->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])->all());
            HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id,
                'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth()->toDateString()]);
            $this->{$name} = $user;
        }
        $this->shift = Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => $context->id, 'user_id' => $this->worker->id,
            'starts_at' => now()->subHours(4), 'ends_at' => now()->subMinutes(15), 'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress', 'started_by' => $this->worker->id, 'created_by' => $this->worker->id]);
        $this->next = Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id,
            'service_context_id' => $context->id, 'user_id' => $this->incoming->id,
            'starts_at' => $this->shift->ends_at->copy()->addMinutes(15), 'ends_at' => $this->shift->ends_at->copy()->addHours(4),
            'status' => 'scheduled', 'created_by' => $this->worker->id]);
        $this->actingAs($this->worker);
    }

    public function test_source_is_current_scalar_only_and_denies_an_unrelated_worker(): void
    {
        $response = $this->getJson($this->sourceUrl())->assertOk()->assertJsonPath('can.create', true)
            ->assertJsonPath('handover_id', null)->assertJsonPath('actor_id', (int) $this->worker->id);
        $this->assertSame(['actor_id', 'can', 'expected_source', 'expected_version', 'handover_id', 'scope', 'shift_id', 'status', 'version'], $this->sortedKeys($response->json()));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->actingAs($this->other)->getJson($this->sourceUrl())->assertNotFound();
    }

    public function test_create_preserves_hashed_whitespace_unicode_and_writes_checked_outputs(): void
    {
        $body = $this->body(fields: ['handover_notes' => "  Original / care\nline\u{2028}end  ", 'submit' => false]);
        $response = $this->send($body)->assertOk()->assertJsonPath('outcome', 'confirmed')->assertJsonPath('saved.version', 1);
        $this->assertSame($this->identity($body), $response->json('identity'));
        $row = ShiftHandover::query()->firstOrFail();
        $this->assertSame($body['fields']['handover_notes'], $row->handover_notes);
        $this->assertSame('draft', $row->status);
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
        $this->assertSame(1, DB::table('operations_handover_completions')->count());
        $this->assertSame(1, DB::table('operations_handover_witnesses')->count());
        $this->assertDatabaseHas('timeline_events', ['source_id' => $row->id, 'subject' => 'Shift handover created']);
        $audit = AuditLog::query()->where('action', 'operations.handover.command.completed')->firstOrFail();
        $this->assertSame($body['request_id'], $audit->meta['request_id']);
        $this->assertStringNotContainsString('Original / care', json_encode($audit->meta));
        $this->assertNull($audit->ip_address);
    }

    public function test_update_and_versioned_submit_increment_and_recover_exact_original_attempts(): void
    {
        $first = $this->body(); $create = $this->send($first)->assertOk(); $id = $create->json('saved.handover_id');
        $edit = $this->body('update', $id, ['handover_notes' => 'Edited note', 'incoming_shift_id' => (int) $this->next->id, 'submit' => false]);
        $this->send($edit, 'update', $id)->assertOk()->assertJsonPath('saved.version', 2)->assertJsonPath('saved.status', 'draft');
        $submit = $this->body('submit', $id);
        $this->send($submit, 'submit', $id)->assertOk()->assertJsonPath('saved.version', 3)->assertJsonPath('saved.status', 'submitted');
        $this->getJson($this->lookupUrl($submit, 'submit', $id))->assertOk()->assertJsonPath('result.outcome', 'confirmed')
            ->assertJsonPath('result.saved.version', 3)->assertJsonPath('current.expected_version', 3);
        $this->assertSame(3, DB::table('operations_handover_attempts')->count());
        $this->assertDatabaseHas('timeline_events', ['source_id' => $id, 'subject' => 'Shift handover submitted']);
    }

    public function test_duplicate_original_uuid_returns_original_receipt_without_another_save(): void
    {
        $body = $this->body(); $first = $this->send($body)->assertOk()->json();
        $this->send($body)->assertOk()->assertExactJson($first);
        $this->assertSame(1, ShiftHandover::query()->count());
        $this->assertSame(1, DB::table('operations_handover_completions')->count());
        $this->assertSame(1, ShiftHandover::query()->firstOrFail()->version);
    }

    public function test_uuid_collision_with_another_intent_is_unknown_not_refusal_or_replay(): void
    {
        $body = $this->body(); $this->send($body)->assertOk();
        $other = $this->body(fields: ['handover_notes' => 'Second intent', 'submit' => false], uuid: $body['request_id']);
        $this->send($other)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
        $this->assertSame('Original note', ShiftHandover::query()->firstOrFail()->handover_notes);
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
    }

    public function test_field_validation_is_matched_pre_writer_refusal_and_a_new_preparation_can_save(): void
    {
        $body = $this->body(fields: ['handover_notes' => '', 'submit' => false]);
        $this->send($body)->assertStatus(422)->assertJsonPath('outcome', 'refused')->assertJsonPath('saved', null)
            ->assertJsonPath('identity', $this->identity($body))->assertJsonStructure(['errors' => ['handover_notes']]);
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->send($this->body())->assertOk()->assertJsonPath('outcome', 'confirmed');
    }

    public function test_stale_source_or_version_refuses_before_invocation_without_disclosing_new_text(): void
    {
        $create = $this->send($this->body())->assertOk(); $id = $create->json('saved.handover_id');
        $stale = $this->body('update', $id, ['handover_notes' => 'Local note', 'submit' => false]);
        DB::table('shift_handovers')->where('id', $id)->update(['handover_notes' => 'New private text', 'version' => 7]);
        $reply = $this->send($stale, 'update', $id)->assertStatus(409)->assertJsonPath('outcome', 'refused');
        $this->assertStringNotContainsString('New private text', $reply->getContent());
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
    }

    public function test_exact_incoming_shift_is_required_before_combined_submission(): void
    {
        $body = $this->body(fields: ['handover_notes' => 'Ready', 'submit' => true]);
        $this->send($body)->assertStatus(422)->assertJsonPath('outcome', 'refused')->assertJsonStructure(['errors' => ['incoming_shift_id']]);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    public function test_controlled_input_denial_is_pre_writer_and_never_echoes_credential(): void
    {
        $body = $this->body(fields: ['handover_notes' => 'Note', 'submit' => false, 'cd_result' => 'verified', 'cd_witness_id' => (int) $this->incoming->id]);
        $body['cd_witness_credential'] = 'not-for-output';
        $reply = $this->send($body)->assertNotFound()->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
        $this->assertStringNotContainsString('not-for-output', $reply->getContent());
        $this->assertSame(0, ShiftHandover::query()->count());
    }

    public function test_false_handover_save_rolls_back_and_original_uuid_cannot_invoke_again(): void
    {
        $body = $this->body(); $reached = 0;
        $this->withModelListener(ShiftHandover::class, 'saving', function () use (&$reached) { $reached++; return false; }, function () use ($body): void {
            $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed');
            $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed');
        });
        $this->assertSame(1, $reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, DB::table('operations_handover_completions')->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result', null);
    }

    public function test_false_normal_action_audit_rolls_back_handover_and_timeline(): void
    {
        $body = $this->body(); $reached = false;
        $this->withModelListener(AuditLog::class, 'saving', function (AuditLog $row) use (&$reached) {
            if ($row->action === 'shift.handover.created') { $reached = true; return false; }
        }, fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, TimelineEvent::query()->where('source_type', ShiftHandover::class)->count());
        $this->assertSame(0, DB::table('operations_handover_completions')->count());
    }

    public function test_false_timeline_write_rolls_back_and_has_no_witness(): void
    {
        $body = $this->body(); $reached = false;
        $this->withModelListener(TimelineEvent::class, 'saving', function () use (&$reached) { $reached = true; return false; },
            fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
    }

    public function test_saved_observer_cannot_replace_the_prepared_handover_note(): void
    {
        $body = $this->body(); $reached = false;
        $this->withModelListener(ShiftHandover::class, 'saved', function (ShiftHandover $row) use (&$reached): void {
            $reached = true; DB::table('shift_handovers')->where('id', $row->id)->update(['handover_notes' => 'Observer replacement']);
        }, fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_completions')->count());
    }

    public function test_committing_access_revocation_is_reached_and_rolls_back_domain_outputs(): void
    {
        $body = $this->body(); $reached = false;
        $this->withConnectionListener(TransactionCommitting::class, function (TransactionCommitting $event) use (&$reached): void {
            if (! $reached && $event->connection->transactionLevel() === 1 && DB::table('operations_handover_completions')->exists()) {
                $reached = true; $this->denyReads();
            }
        }, fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_completions')->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        $this->getJson($this->sourceUrl())->assertOk(); // The authority mutation was in the rolled-back root.
    }

    public function test_committed_access_loss_withholds_confirmation_without_relabelling_saved_row(): void
    {
        $body = $this->body(); $reached = false;
        $this->withConnectionListener(TransactionCommitted::class, function (TransactionCommitted $event) use (&$reached): void {
            if (! $reached && $event->connection->transactionLevel() === 0 && DB::table('operations_handover_completions')->exists()) {
                $reached = true; $this->denyReads();
            }
        }, fn () => $this->send($body)->assertStatus(202)->assertJsonPath('saved', null));
        $this->assertTrue($reached);
        $this->assertSame(1, ShiftHandover::query()->count());
        $this->assertSame(1, DB::table('operations_handover_completions')->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        $this->getJson($this->lookupUrl($body))->assertForbidden();
    }

    public function test_missing_post_root_witness_stays_unknown_and_never_replays(): void
    {
        $body = $this->body(); $reached = false;
        $this->withModelListener(OperationsHandoverWitness::class, 'saving', function () use (&$reached) { $reached = true; return false; },
            fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(1, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        $this->send($body)->assertStatus(202);
        $this->assertSame(1, ShiftHandover::query()->firstOrFail()->version);
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result', null);
    }

    public function test_tampered_receipt_is_unknown_even_with_a_witness_row(): void
    {
        $body = $this->body(); $this->send($body)->assertOk();
        $row = DB::table('operations_handover_completions')->first();
        $receipt = json_decode($row->receipt, true); $receipt['version'] = 500;
        DB::table('operations_handover_completions')->where('id', $row->id)->update(['receipt' => json_encode($receipt)]);
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result', null);
        $this->assertSame(1, ShiftHandover::query()->firstOrFail()->version);
    }

    public function test_old_checked_receipt_remains_historical_after_a_later_legitimate_edit(): void
    {
        $body = $this->body(); $id = $this->send($body)->assertOk()->json('saved.handover_id');
        $edit = $this->body('update', $id, ['handover_notes' => 'Later note', 'submit' => false]);
        $this->send($edit, 'update', $id)->assertOk()->assertJsonPath('saved.version', 2);
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result.saved.version', 1)->assertJsonPath('current.expected_version', 2);
        $this->assertSame('Later note', ShiftHandover::query()->firstOrFail()->handover_notes);
    }

    public function test_current_incoming_worker_cannot_read_another_actors_original_attempt(): void
    {
        $body = $this->body(fields: ['handover_notes' => 'Private note', 'incoming_shift_id' => (int) $this->next->id, 'submit' => false]);
        $this->send($body)->assertOk();
        $this->actingAs($this->incoming)->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result', null);
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
    }

    public function test_root_raw_close_reopen_cannot_mint_a_confirmed_witness(): void
    {
        $body = $this->body(); $reached = false;
        try {
            $this->withModelListener(ShiftHandover::class, 'saved', function () use (&$reached): void {
                $reached = true; $pdo = DB::connection()->getPdo(); $pdo->commit(); $pdo->beginTransaction();
            }, fn () => $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed'));
            $this->assertTrue($reached);
            $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        } finally {
            // This isolated test owns the replacement frame it deliberately created.
            if (DB::connection()->transactionLevel() > 0) { DB::connection()->rollBack(0); }
            elseif (DB::connection()->getPdo()->inTransaction()) { DB::connection()->getPdo()->rollBack(); }
        }
        $this->assertSame(1, ShiftHandover::query()->count()); // Never claim rollback of the raw committed writer.
    }

    public function test_retrieved_listener_cannot_replace_a_retained_lifecycle_field_before_edit(): void
    {
        $create = $this->send($this->body())->assertOk(); $id = $create->json('saved.handover_id');
        $edit = $this->body('update', $id, ['handover_notes' => 'New intended note', 'submit' => false]);
        $reached = false;
        $this->withModelListener(ShiftHandover::class, 'retrieved', function (ShiftHandover $row) use (&$reached): void {
            if (array_key_exists('handover_notes', $row->getAttributes())) { $reached = true; $row->acknowledged_by = $this->incoming->id; }
        }, fn () => $this->send($edit, 'update', $id)->assertStatus(202));
        $this->assertTrue($reached);
        $row = ShiftHandover::query()->findOrFail($id);
        $this->assertSame('Original note', $row->handover_notes);
        $this->assertNull($row->acknowledged_by);
        $this->assertSame(1, $row->version);
        $this->assertSame(1, DB::table('operations_handover_completions')->count());
    }

    public function test_timeline_saved_listener_cannot_replace_the_prepared_body(): void
    {
        $body = $this->body(); $reached = false;
        $this->withModelListener(TimelineEvent::class, 'saved', function (TimelineEvent $row) use (&$reached): void {
            $reached = true; DB::table('timeline_events')->where('id', $row->id)->update(['body' => 'Changed after normal save']);
        }, fn () => $this->send($body)->assertStatus(202));
        $this->assertTrue($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
    }

    public function test_current_grant_loss_after_witness_commit_hides_receipt_and_later_recovery_is_read_only(): void
    {
        $body = $this->body(); $reached = false;
        $this->withConnectionListener(TransactionCommitted::class, function (TransactionCommitted $event) use (&$reached): void {
            if (! $reached && $event->connection->transactionLevel() === 0 && DB::table('operations_handover_witnesses')->exists()) {
                $reached = true; $this->denyReads();
            }
        }, fn () => $this->send($body)->assertStatus(202)->assertJsonPath('saved', null));
        $this->assertTrue($reached);
        $this->assertSame(1, ShiftHandover::query()->count());
        $this->assertSame(1, DB::table('operations_handover_witnesses')->count());
        $this->getJson($this->lookupUrl($body))->assertForbidden();
        $ids = Permission::query()->whereIn('key', ['handovers.create', 'shifts.update', 'shifts.viewAssigned'])->pluck('id');
        $this->worker->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])->all());
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result.outcome', 'confirmed');
        $this->assertSame(1, ShiftHandover::query()->firstOrFail()->version);
    }

    public function test_default_shared_service_is_not_rebound_and_default_submit_revision_is_unchanged(): void
    {
        $service = app(ShiftHandoverService::class);
        $this->assertSame(ShiftHandoverService::class, $service::class);
        $saved = $service->save($this->shift, $this->worker, ['handover_notes' => 'Legacy draft', 'incoming_shift_id' => (int) $this->next->id, 'submit' => false])['handover'];
        $before = (int) $saved->version;
        $submitted = $service->submit($saved, $this->worker);
        $this->assertSame($before, (int) $submitted->version);
        $this->assertSame('submitted', $submitted->status);
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    private function sourceUrl(): string { return '/operations/shifts/'.$this->shift->id.'/handover-command-source'; }

    public function test_operations_and_attendance_publish_protocol_only_when_schema_and_key_are_ready(): void
    {
        $this->assertTrue(\App\Services\Operations\OperationsHandoverReadiness::available());
        $this->get('/operations/handovers')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', 1));
        $this->get('/attendance')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', 1));
        config(['app.key' => '']);
        $this->get('/operations/handovers')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', null));
        $this->get('/attendance')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', null));
        $this->getJson($this->sourceUrl())->assertStatus(503);
        $this->assertSame(0, ShiftHandover::query()->count());
    }

    public function test_partial_schema_is_not_advertised_and_cannot_start_a_writer(): void
    {
        $body = $this->body(); $reached = false;
        Schema::drop('operations_handover_witnesses');
        try {
            $this->assertFalse(\App\Services\Operations\OperationsHandoverReadiness::available());
            $this->get('/operations/handovers')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', null));
            $this->get('/attendance')->assertInertia(fn (Assert $page) => $page->where('handoverCommandProtocol', null));
            $this->withModelListener(ShiftHandover::class, 'saving', function () use (&$reached): void { $reached = true; },
                fn () => $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed'));
            $this->assertFalse($reached);
            $this->assertSame(0, ShiftHandover::query()->count());
            $this->assertSame(0, DB::table('operations_handover_attempts')->count());
        } finally {
            // The schema is shared with later tests in this process: reinstall the evidence tables.
            $this->reinstallHandoverEvidenceSchema();
        }
    }

    public function test_missing_original_uuid_unique_constraint_holds_protocol_readiness(): void
    {
        Schema::table('operations_handover_attempts', fn ($table) => $table->dropUnique(['actor_id', 'request_id']));
        try {
            $this->assertFalse(\App\Services\Operations\OperationsHandoverReadiness::available());
            $this->getJson($this->sourceUrl())->assertStatus(503);
            $this->assertSame(0, ShiftHandover::query()->count());
        } finally {
            $this->reinstallHandoverEvidenceSchema();
        }
    }

    private function reinstallHandoverEvidenceSchema(): void
    {
        $migration = require database_path('migrations/2026_10_12_235100_create_operations_handover_command_evidence.php');
        $migration->down();
        $migration->up();
        $this->assertTrue(\App\Services\Operations\OperationsHandoverReadiness::available());
    }

    public function test_committed_reservation_listener_failure_is_unknown_and_never_invokes_or_replays(): void
    {
        $body = $this->body(); $reached = false;
        $this->withConnectionListener(TransactionCommitted::class, function (TransactionCommitted $event) use (&$reached): void {
            if (! $reached && $event->connection->transactionLevel() === 0
                && DB::table('operations_handover_attempts')->exists()
                && ! DB::table('operations_handover_completions')->exists()) {
                $reached = true;
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Deliberate post-reservation listener failure');
            }
        }, fn () => $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null));
        $this->assertTrue($reached);
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_completions')->count());
        $this->assertSame(0, DB::table('operations_handover_witnesses')->count());
        $this->getJson($this->lookupUrl($body))->assertOk()->assertJsonPath('result', null);
        $this->send($body)->assertStatus(202)->assertJsonPath('outcome', 'unconfirmed');
        $this->assertSame(1, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, ShiftHandover::query()->count());
    }

    public function test_readable_source_then_read_grant_withdrawal_before_post_returns_denial_not_editable_refusal(): void
    {
        $body = $this->body(fields: ['handover_notes' => 'Retained private local typing', 'submit' => false]);
        $this->denyReads(); $reached = false;
        $this->withModelListener(ShiftHandover::class, 'saving', function () use (&$reached): void { $reached = true; }, function () use ($body): void {
            $reply = $this->send($body)->assertForbidden()->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
            $this->assertSame($this->identity($body), $reply->json('identity'));
            $this->assertArrayNotHasKey('errors', $reply->json());
            $this->assertStringNotContainsString('Retained private local typing', $reply->getContent());
        });
        $this->assertFalse($reached);
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    public function test_withdrawn_read_is_checked_before_hash_valid_field_validation_feedback(): void
    {
        $body = $this->body(fields: ['handover_notes' => '', 'submit' => false]);
        $this->denyReads();
        $reply = $this->send($body)->assertForbidden()->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
        $this->assertArrayNotHasKey('errors', $reply->json());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    public function test_readable_source_then_site_withdrawal_before_post_is_concealed_and_has_no_attempt(): void
    {
        $body = $this->body();
        DB::table('sites')->where('id', $this->shift->site_id)->update(['is_active' => false]);
        $reply = $this->send($body)->assertNotFound()->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
        $this->assertArrayNotHasKey('errors', $reply->json());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    public function test_readable_source_then_canonical_assignment_withdrawal_before_post_is_concealed_without_replay(): void
    {
        $body = $this->body();
        DB::table('shifts')->where('id', $this->shift->id)->update(['user_id' => $this->other->id]);
        $reply = $this->send($body)->assertNotFound()->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
        $this->assertArrayNotHasKey('errors', $reply->json());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
    }

    public function test_invalid_one_shot_credentials_refuse_for_current_readers_but_never_mask_withdrawn_read(): void
    {
        $body = $this->body();
        $credentials = [str_repeat('x', 256), "one\0shot"];
        foreach ($credentials as $credential) {
            $wire = [...$body, 'cd_witness_credential' => $credential];
            $this->send($wire)->assertStatus(422)->assertJsonPath('outcome', 'refused')->assertJsonPath('saved', null)
                ->assertJsonPath('identity', $this->identity($body))
                ->assertJsonPath('errors.cd_witness_credential', 'Check the witness PIN.');
        }
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, ShiftHandover::query()->count());
        $this->denyReads(); $reached = false;
        $this->withModelListener(ShiftHandover::class, 'saving', function () use (&$reached): void { $reached = true; }, function () use ($body, $credentials): void {
            foreach ($credentials as $credential) {
                $reply = $this->send([...$body, 'cd_witness_credential' => $credential])->assertForbidden()
                    ->assertJsonPath('outcome', 'unconfirmed')->assertJsonPath('saved', null);
                $this->assertSame($this->identity($body), $reply->json('identity'));
                $this->assertArrayNotHasKey('errors', $reply->json());
            }
        });
        $this->assertFalse($reached);
        $this->assertSame(0, DB::table('operations_handover_attempts')->count());
        $this->assertSame(0, ShiftHandover::query()->count());
    }

    private function body(string $action = 'create', ?int $rowId = null, ?array $fields = null, ?string $uuid = null): array
    {
        $source = $this->getJson($this->sourceUrl().($rowId === null ? '' : '?handover_id='.$rowId))->assertOk()->json();
        $fields = $action === 'submit' ? new \stdClass : ($fields ?? ['handover_notes' => 'Original note', 'submit' => false]);
        $canonical = ['action' => $action, 'shift_id' => (int) $this->shift->id, 'handover_id' => $rowId,
            'expected_version' => $action === 'create' ? null : $source['expected_version'], 'expected_source' => $source['expected_source'], 'fields' => $fields];
        return ['request_id' => $uuid ?? (string) Str::uuid(), 'expected_source' => $source['expected_source'],
            'expected_version' => $canonical['expected_version'], 'intent_hash' => hash('sha256', OperationsHandoverIntent::json($canonical)), 'fields' => $fields];
    }

    private function send(array $body, string $action = 'create', ?int $rowId = null)
    {
        $base = '/operations/shifts/'.$this->shift->id;
        return match ($action) {
            'create' => $this->postJson($base.'/handover-commands', $body),
            'update' => $this->putJson($base.'/handovers/'.$rowId.'/command', $body),
            'submit' => $this->patchJson($base.'/handovers/'.$rowId.'/command/submit', $body),
        };
    }

    private function identity(array $body, string $action = 'create', ?int $rowId = null): array
    {
        return ['version' => 1, 'actor_id' => (int) $this->worker->id, 'request_id' => $body['request_id'], 'action' => $action,
            'shift_id' => (int) $this->shift->id, 'requested_handover_id' => $rowId, 'expected_version' => $body['expected_version'],
            'expected_source' => $body['expected_source'], 'intent_hash' => $body['intent_hash']];
    }

    private function lookupUrl(array $body, string $action = 'create', ?int $rowId = null): string
    {
        return '/operations/shifts/'.$this->shift->id.'/handover-attempts/'.$body['request_id'].'?'.http_build_query([
            'action' => $action, 'requested_handover_id' => $rowId ?? 'none', 'expected_version' => $body['expected_version'] ?? 'none',
            'expected_source' => $body['expected_source'], 'intent_hash' => $body['intent_hash']]);
    }

    private function denyReads(): void
    {
        $ids = Permission::query()->whereIn('key', ['handovers.create', 'handovers.viewAny', 'shifts.viewAny', 'shifts.viewAssigned', 'shifts.update', 'shifts.manageAny'])->pluck('id');
        $this->worker->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => false]])->all());
    }

    private function withModelListener(string $class, string $event, \Closure $listener, \Closure $run): void
    {
        $original = Model::getEventDispatcher(); $scoped = clone $original;
        Model::setEventDispatcher($scoped); $scoped->listen('eloquent.'.$event.': '.$class, $listener);
        try { $run(); } finally { Model::setEventDispatcher($original); }
    }

    private function withConnectionListener(string $event, \Closure $listener, \Closure $run): void
    {
        $connection = DB::connection(); $original = $connection->getEventDispatcher(); $scoped = clone $original;
        $scoped->listen($event, $listener); $connection->setEventDispatcher($scoped);
        try { $run(); } finally { $connection->setEventDispatcher($original); }
    }

    private function sortedKeys(array $row): array { $keys = array_keys($row); sort($keys); return $keys; }
}
