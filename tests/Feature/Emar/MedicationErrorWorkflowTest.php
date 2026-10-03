<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoomAlert;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Reporting\MedicationExportAudit;
use App\Services\Medication\Reporting\RecordsReportingSettings;
use App\Services\Tasks\Providers\MedicationErrorProvider;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MedicationErrorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $reporter;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->reporter = $this->staff('support_worker');
        $this->manager = $this->staff('team_lead');
        $this->client->supportWorkers()->attach($this->reporter->id);
        Shift::factory()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->client->service_context_id,
            'user_id' => $this->reporter->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null, 'started_by' => $this->reporter->id, 'status' => 'in_progress',
        ]);
    }

    private function staff(string $role): User
    {
        $user = $this->makeRoleUser($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subDay()]);

        return $user;
    }

    protected function makeRoleUser(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);

        return $user;
    }

    protected function grantPermissions(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', $keys)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function payload(array $extra = []): array
    {
        return array_replace([
            'client_id' => $this->client->id, 'client_medication_id' => null, 'error_type' => 'documentation',
            'occurred_at' => now('Pacific/Auckland')->subMinutes(10)->format('Y-m-d\TH:i'), 'reached_client' => 'no', 'harm_level' => 'none',
            'description' => 'Synthetic private account with a medicine name.', 'immediate_action' => 'Synthetic action only.', 'report_token' => (string) Str::uuid(),
        ], $extra);
    }

    private function report(array $extra = []): MedicationError
    {
        $this->actingAs($this->reporter)->post('/emar/errors', $this->payload($extra))->assertSessionHasNoErrors()->assertRedirect();

        return MedicationError::query()->latest('id')->firstOrFail();
    }

    private function triage(MedicationError $error): void
    {
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/review', [
            'owner_id' => $this->manager->id, 'investigation_due_at' => now('Pacific/Auckland')->addDay()->format('Y-m-d\TH:i'),
            'reached_client' => $error->reached_client, 'harm_level' => $error->harm_level, 'review_notes' => 'Synthetic triage.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('investigating', $error->fresh()->stage());
    }

    public function test_report_has_account_nz_deadline_and_one_event_on_retry(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertDatabaseCount('medication_error_entries', 1);
        $this->assertDatabaseCount('medication_events', 1);
        $this->assertDatabaseCount('medication_error_report_receipts', 1);
        $error = MedicationError::query()->first();
        $this->assertSame('triage', $error->stage());
        $this->assertSame(now('Pacific/Auckland')->addDay()->toDateString(), $error->triage_due_at->tz('Pacific/Auckland')->toDateString());
        $this->assertSame('23:59', $error->triage_due_at->tz('Pacific/Auckland')->format('H:i'));
    }

    public function test_support_worker_cannot_manage_even_with_dose_correction_permission(): void
    {
        $error = $this->report();
        $this->grantPermissions($this->reporter, ['medications.administer.correct']);
        foreach (['review', 'resolve', 'close', 'reopen', 'notes', 'actions'] as $path) {
            $this->actingAs($this->reporter)->post('/emar/errors/'.$error->id.'/'.$path, [])->assertForbidden();
        }
    }

    public function test_actions_and_disclosure_block_close_then_independent_close_and_reopen_append_history(): void
    {
        $error = $this->report(['reached_client' => 'yes', 'harm_level' => 'none']);
        $this->triage($error);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/actions', ['owner_id' => $this->manager->id, 'due_at' => now('Pacific/Auckland')->addDay()->format('Y-m-d\TH:i'), 'description' => 'Synthetic action'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Synthetic close'])->assertSessionHasErrors('status');
        $action = $error->actions()->first();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/actions/'.$action->id.'/complete', ['completion_note' => 'Synthetic complete'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get('/emar/errors?tab=actions')->assertInertia(fn (Assert $page) => $page->has('errors', 1)->where('stats.actions', 1));
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/disclosure', ['state' => 'told', 'who' => ['person'], 'by' => 'Synthetic manager', 'at' => now('Pacific/Auckland')->subMinute()->format('Y-m-d\TH:i'), 'how' => 'In person'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Synthetic close'])->assertRedirect()->assertSessionHasNoErrors();
        $closedEntry = $error->entries()->where('kind', 'closed')->firstOrFail();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/reopen', ['reason' => 'New synthetic evidence'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('investigating', $error->fresh()->stage());
        $this->assertSame('Synthetic close', $closedEntry->fresh()->text);
        $this->assertSame(1, $error->entries()->where('kind', 'reopened')->count());
    }

    public function test_reporter_with_manager_permission_cannot_close_or_reopen_own_error(): void
    {
        $error = $this->report();
        $this->triage($error);
        $this->grantPermissions($this->reporter, ['medications.errors.manage']);
        $this->actingAs($this->reporter)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Own close'])->assertSessionHasErrors('status');
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Independent'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reporter)->post('/emar/errors/'.$error->id.'/reopen', ['reason' => 'Own reopen'])->assertSessionHasErrors('status');
    }

    public function test_moderate_harm_creates_one_neutral_incident_and_no_private_signal_payload(): void
    {
        $payload = $this->payload(['reached_client' => 'yes', 'harm_level' => 'moderate', 'description' => 'PRIVATE synthetic medication narrative', 'immediate_action' => 'PRIVATE response']);
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $error = MedicationError::query()->firstOrFail();
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/link-incident')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('client_incidents', 1);
        $incident = ClientIncident::query()->first();
        $this->assertStringNotContainsString('PRIVATE', $incident->description.$incident->immediate_action_taken);
        foreach (Signal::query()->get() as $signal) {
            $this->assertStringNotContainsString('PRIVATE', json_encode($signal->getAttributes()));
        }
    }

    public function test_error_close_keeps_submitted_incident_open_and_ready_for_incidents_authority(): void
    {
        $error = $this->report(['reached_client' => 'yes', 'harm_level' => 'moderate']);
        $this->triage($error);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/disclosure', ['state' => 'told', 'who' => ['person'], 'by' => 'Synthetic manager', 'at' => now('Pacific/Auckland')->subMinute()->format('Y-m-d\TH:i'), 'how' => 'In person'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'PRIVATE close narrative'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('closed', $error->fresh()->status);
        $this->assertSame('submitted', $error->incident()->first()->status);
        $this->actingAs($this->manager)->get('/emar/errors?tab=closed&error='.$error->id)->assertInertia(fn (Assert $page) => $page->where('detail.incident.ready_to_close', true));
    }

    public function test_duplicate_account_retry_adds_one_account_to_existing_error(): void
    {
        $error = $this->report();
        $payload = $this->payload();
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertSessionHasErrors('duplicate');
        $payload['duplicate_id'] = $error->id;
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reporter)->post('/emar/errors', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertSame(1, $error->entries()->where('kind', 'account')->count());
    }

    public function test_entries_cannot_be_overwritten(): void
    {
        $entry = $this->report()->entries()->first();
        $this->expectException(\LogicException::class);
        $entry->update(['text' => 'Overwrite']);
    }

    public function test_event_failure_rolls_back_error_accounts_incident_and_workflow(): void
    {
        $this->mock(MedicationEventRecorder::class, fn ($mock) => $mock->shouldReceive('appendMany')->once()->andThrow(new \RuntimeException('Synthetic event failure')));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->reporter)->post('/emar/errors', $this->payload(['reached_client' => 'yes', 'harm_level' => 'moderate']));
            $this->fail('The report must not commit without its event.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic event failure', $exception->getMessage());
        }
        foreach (['medication_errors', 'medication_error_entries', 'client_incidents', 'medication_events', 'medication_error_report_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_reporter_receives_neutral_history_but_no_investigation_text(): void
    {
        $error = $this->report();
        $this->triage($error);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/notes', ['text' => 'PRIVATE investigator note'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reporter)->get('/emar/errors?error='.$error->id)->assertInertia(fn (Assert $page) => $page
            ->where('detail.entries.1.kind', 'triaged')->where('detail.entries.1.text', null)->where('detail.entries.1.data', [])
            ->has('detail.entries', 2)->where('detail.review_notes', null)->where('detail.close_blockers', []));
    }

    public function test_neutral_export_requires_purpose_and_omits_private_narrative(): void
    {
        $error = $this->report(['description' => 'PRIVATE account for export regression']);
        $this->grantPermissions($this->manager, ['medications.reports.view', 'medications.reports.export']);
        $this->actingAs($this->manager)->get('/emar/errors/export')->assertSessionHasErrors('purpose');
        $response = $this->actingAs($this->manager)->get('/emar/errors/export?purpose=Synthetic+safety+review')->assertOk();
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringContainsString($error->reference_number, $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertDatabaseHas('medication_events', ['kind' => 'export.created', 'actor_id' => $this->manager->id, 'subject_id' => 'errors']);
    }

    public function test_audit_reader_cannot_use_legacy_error_export_without_report_export_permission(): void
    {
        $reader = $this->staff('auditor');
        $this->grantPermissions($reader, ['medications.view', 'medications.audit.view', 'medications.audit.export', 'medications.reports.view']);
        $reader->permissionOverrides()->syncWithoutDetaching([
            Permission::query()->where('key', 'medications.reports.export')->sole()->id => ['allowed' => false],
        ]);
        $reader->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($reader)->get('/emar/errors')->assertInertia(fn (Assert $page) => $page->where('can.export', false));
        $this->actingAs($reader)->get('/emar/errors/export?type=audit&purpose=audit')->assertForbidden();
        $this->assertDatabaseCount('medication_events', 0);
    }

    public function test_legacy_error_export_rechecks_permission_after_preparing_the_file(): void
    {
        $this->report(['description' => 'PRIVATE export revocation account']);
        $this->grantPermissions($this->manager, ['medications.reports.view', 'medications.reports.export']);
        $audit = app(MedicationExportAudit::class);
        // Proxy the existing final service only at its after-render seam, then
        // execute its real current-evidence release check with a revoked grant.
        $proxy = \Mockery::mock($audit);
        $proxy->shouldReceive('record')->once()->andReturnUsing(function (...$arguments) use ($audit): void {
            $this->manager->permissionOverrides()->syncWithoutDetaching([
                Permission::query()->where('key', 'medications.reports.export')->sole()->id => ['allowed' => false],
            ]);
            $audit->record(...$arguments);
        });
        $this->app->instance(MedicationExportAudit::class, $proxy);
        $response = $this->actingAs($this->manager)->get('/emar/errors/export?purpose=review');
        $response->assertForbidden();
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertDatabaseCount('medication_events', 1);
    }

    public function test_manual_operational_alert_uses_neutral_summary_and_canonical_read_scope(): void
    {
        $error = $this->report(['description' => 'PRIVATE manual alert account']);
        $this->grantPermissions($this->manager, ['controlRoom.alerts.create']);
        $this->actingAs($this->manager)->post('/control-room/incidents/create-alert', [
            'source_type' => 'medication_error', 'source_id' => $error->id, 'severity' => 'low',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $alert = ControlRoomAlert::query()->sole();
        $this->assertStringNotContainsString('PRIVATE', json_encode($alert->context));
        $this->assertStringContainsString('Details are held', $alert->context['description']);

        $otherPerson = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $unreadable = MedicationError::query()->create([
            'client_id' => $otherPerson->id, 'error_type' => 'other', 'severity' => 'minor',
            'description' => 'PRIVATE unassigned source', 'reported_by' => $this->reporter->id,
            'reported_at' => now(), 'status' => 'reported',
        ]);
        $this->grantPermissions($this->reporter, ['controlRoom.alerts.create']);
        $this->actingAs($this->reporter)->post('/control-room/incidents/create-alert', [
            'source_type' => 'medication_error', 'source_id' => $unreadable->id, 'severity' => 'low',
        ])->assertNotFound();
        $this->assertDatabaseCount('control_room_alerts', 1);
    }

    public function test_serious_incident_requires_recorded_actual_action_and_does_not_copy_it(): void
    {
        $this->actingAs($this->reporter)->post('/emar/errors', $this->payload(['reached_client' => 'yes', 'harm_level' => 'moderate', 'immediate_action' => '']))->assertSessionHasErrors('immediate_action');
        $this->assertDatabaseCount('medication_errors', 0);
    }

    public function test_changed_report_replay_is_conflict_and_preserves_original(): void
    {
        $payload = $this->payload();
        $this->actingAs($this->reporter)->postJson('/emar/errors', $payload)->assertRedirect();
        $payload['description'] = 'Changed narrative after save';
        $this->actingAs($this->reporter)->postJson('/emar/errors', $payload)->assertStatus(409)->assertJsonValidationErrors('report_token');
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertStringContainsString('Synthetic private account', MedicationError::query()->first()->description);
    }

    public function test_changed_duplicate_account_replay_is_conflict_and_preserves_original(): void
    {
        $error = $this->report();
        $payload = $this->payload(['duplicate_id' => $error->id]);
        $this->actingAs($this->reporter)->postJson('/emar/errors', $payload)->assertRedirect();
        $payload['harm_level'] = 'moderate';
        $payload['reached_client'] = 'yes';
        $this->actingAs($this->reporter)->postJson('/emar/errors', $payload)->assertStatus(409)->assertJsonValidationErrors('report_token');
        $this->assertSame(1, $error->entries()->where('kind', 'account')->count());
        $this->assertDatabaseCount('medication_events', 2);
    }

    public function test_tasks_omit_unassigned_same_site_person_and_other_reporter_and_keep_neutral_summary(): void
    {
        $own = $this->report();
        $otherPerson = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        foreach ([$this->client, $otherPerson] as $person) {
            MedicationError::query()->create(['client_id' => $person->id, 'error_type' => 'other', 'severity' => 'minor', 'description' => 'PRIVATE outsider', 'reported_by' => $this->manager->id, 'reported_at' => now(), 'status' => 'reported']);
        }
        $tasks = app(MedicationErrorProvider::class)->authorizedTasks($this->reporter);
        $this->assertCount(1, $tasks);
        $this->assertSame('med_error-'.$own->id, $tasks[0]->id);
        $this->assertStringNotContainsString('PRIVATE', $tasks[0]->description);
        $this->assertStringNotContainsString('medicine name', $tasks[0]->description);
        $this->assertCount(0, app(MedicationErrorProvider::class)->authorizedTasks($this->reporter, ['id' => $own->id + 1]));
    }

    public function test_cross_person_controlled_record_is_omitted_from_tasks_and_register(): void
    {
        $medicine = ClientMedication::factory()->create(['client_id' => $this->client->id, 'controlled_drug' => true]);
        $error = MedicationError::query()->create(['client_id' => $this->client->id, 'client_medication_id' => $medicine->id, 'error_type' => 'other', 'severity' => 'minor', 'description' => 'PRIVATE CD', 'reported_by' => $this->reporter->id, 'reported_at' => now(), 'status' => 'reported']);
        $reader = $this->staff('auditor');
        $this->grantPermissions($reader, ['medications.view', 'medications.audit.view']);
        $this->client->supportWorkers()->attach($reader->id);
        $this->assertCount(0, app(MedicationErrorProvider::class)->authorizedTasks($reader));
        $this->actingAs($reader)->get('/emar/errors?tab=triage')->assertInertia(fn (Assert $page) => $page->has('errors', 0)->where('stats.total_open', 0));
        $this->actingAs($reader)->get('/emar/errors?error='.$error->id)->assertNotFound();
    }

    public function test_added_account_makes_the_error_visible_in_your_reports(): void
    {
        $error = MedicationError::query()->create(['client_id' => $this->client->id, 'error_type' => 'other', 'severity' => 'minor', 'description' => 'Synthetic other reporter', 'reported_by' => $this->manager->id, 'reported_at' => now(), 'status' => 'reported']);
        $this->actingAs($this->reporter)->get('/emar/errors?tab=mine')->assertInertia(fn (Assert $page) => $page->has('errors', 0));
        $this->actingAs($this->reporter)->post('/emar/errors', $this->payload(['duplicate_id' => $error->id]))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->reporter)->get('/emar/errors?tab=mine')->assertInertia(fn (Assert $page) => $page->has('errors', 1)->where('errors.0.id', $error->id));
        $this->assertCount(1, app(MedicationErrorProvider::class)->authorizedTasks($this->reporter));
    }

    public function test_sac_proposal_never_closes_without_explicit_confirmation(): void
    {
        AppSetting::query()->updateOrCreate(['key' => RecordsReportingSettings::SAC], ['value' => 'on']);
        $error = $this->report(['reached_client' => 'yes', 'harm_level' => 'minor']);
        $this->triage($error);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/disclosure', ['state' => 'told', 'who' => ['person'], 'by' => 'Synthetic manager', 'at' => now('Pacific/Auckland')->subMinute()->format('Y-m-d\TH:i'), 'how' => 'In person'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get('/emar/errors?error='.$error->id)->assertInertia(fn (Assert $page) => $page->where('detail.sac.proposed', 4)->where('detail.sac.confirmed', null));
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Synthetic close'])->assertSessionHasErrors('confirmed_sac');
        $this->assertNotSame('closed', $error->fresh()->status);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Synthetic close', 'confirmed_sac' => '4'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(4, $error->fresh()->confirmed_sac);
        $this->assertSame($this->manager->id, (int) $error->fresh()->sac_confirmed_by);
        $this->assertNotNull($error->fresh()->sac_confirmed_at);
    }

    public function test_near_miss_never_receives_sac_even_when_enabled(): void
    {
        AppSetting::query()->updateOrCreate(['key' => RecordsReportingSettings::SAC], ['value' => 'on']);
        $error = $this->report();
        $this->triage($error);
        $this->actingAs($this->manager)->post('/emar/errors/'.$error->id.'/close', ['close_note' => 'Synthetic near miss close', 'confirmed_sac' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($error->fresh()->confirmed_sac);
    }
}
