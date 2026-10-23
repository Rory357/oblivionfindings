<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\FirstAidSourceDelivery;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsCorrectiveActionOwnerChange;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidDeliveryService;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\Tasks\Providers\HsCorrectiveActionProvider;
use App\Services\Tasks\Providers\HsEventProvider;
use App\Services\Tasks\Providers\HsInvestigationProvider;
use App\Services\Tasks\TaskAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

class FirstAidDerivedWorkPrivacyTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    private const PRIVATE_NARRATIVE = 'SyntheticPrivateTreatmentNarrative';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
        $this->grant($this->worker, 'hazards.manage', true);
        $this->grant($this->worker, 'clients.viewAny', false);
        $this->grant($this->worker, 'clients.viewAssigned', true);
        $this->grant($this->worker, 'healthSafety.viewAllSites', false);
    }

    private function grant(User $actor, string $key, bool $allowed): void
    {
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
        // A new request receives current permissions; this test reuses the actor instance.
        $actor->unsetRelation('permissionOverrides');
    }

    /** @return array{Client, FirstAidRecord, HsEvent, HsInvestigation, HsCorrectiveAction} */
    private function derivedJourney(bool $assigned = false): array
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id, 'first_name' => 'Synthetic', 'last_name' => 'PrivateTreatmentPerson']);
        if ($assigned) {
            $client->supportWorkers()->attach($this->worker->id);
        }
        $record = $this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id, 'ambulance_called' => true, 'injury_illness_description' => self::PRIVATE_NARRATIVE]);
        $delivery = FirstAidSourceDelivery::where('first_aid_record_id', $record->id)->where('purpose', 'source_review')->sole();
        app(FirstAidDeliveryService::class)->deliver($delivery->id);
        $event = HsEvent::findOrFail($delivery->fresh()->hs_event_id);
        $event->forceFill(['owner_user_id' => $this->worker->id])->saveQuietly();
        $investigation = HsInvestigation::factory()->create(['hs_event_id' => $event->id, 'lead_investigator_id' => $this->worker->id, 'created_by' => $this->manager->id, 'status' => HsInvestigation::STATUS_IN_PROGRESS, 'started_at' => now(), 'findings_summary' => self::PRIVATE_NARRATIVE]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'hs_investigation_id' => $investigation->id, 'created_by' => $this->manager->id, 'assigned_to_user_id' => $this->worker->id, 'title' => self::PRIVATE_NARRATIVE]);

        return [$client, $record, $event, $investigation, $action];
    }

    private function assertDerivedProviders(HsEvent $event, HsInvestigation $investigation, HsCorrectiveAction $action, bool $visible): void
    {
        foreach ([[HsEventProvider::class, $event, 'hs_event'], [HsInvestigationProvider::class, $investigation, 'hs_investigation'], [HsCorrectiveActionProvider::class, $action, 'corrective_action']] as [$provider, $row, $source]) {
            $items = app($provider)->authorizedTasks($this->worker, ['id' => $row->id, 'include_done' => true]);
            $this->assertSame($visible ? [$source.'-'.$row->id] : [], array_column($items, 'id'));
        }
    }

    private function evidence(HsCorrectiveAction $action): HsAttachment
    {
        $path = 'synthetic/derived-work-'.$action->id.'.pdf';
        Storage::disk('private')->put($path, 'Synthetic private treatment-derived evidence');

        return $action->attachments()->create(['uploaded_by' => $this->manager->id, 'original_name' => 'private-treatment-evidence.pdf', 'path' => $path, 'disk' => 'private', 'mime_type' => 'application/pdf', 'size_bytes' => 43]);
    }

    private function evidenceUrl(HsEvent $event, HsCorrectiveAction $action, ?HsAttachment $attachment = null): string
    {
        return "/health-safety/events/{$event->id}/corrective-actions/{$action->id}/evidence".($attachment ? '/'.$attachment->id : '');
    }

    public function test_actual_tasks_my_day_and_drawers_recheck_current_primary_client_read_for_all_three_derived_sources(): void
    {
        [$client, $record, $event, $investigation, $action] = $this->derivedJourney(true);
        $expected = ['hs_event-'.$event->id, 'hs_investigation-'.$investigation->id, 'corrective_action-'.$action->id];
        $origin = '/tasks?sources=hs_event,hs_investigation,corrective_action&assigned=me';
        $this->actingAs($this->worker)->get($origin)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 3)->where('items', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('myTasks.items', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
        $this->assertDerivedProviders($event, $investigation, $action, true);
        $this->getJson('/tasks/lookup?q='.rawurlencode($action->reference_number))->assertOk()->assertJsonPath('match.ref', $action->reference_number);
        $this->worker->load('permissionOverrides', 'hrEmployeeProfile');
        $client->supportWorkers()->detach($this->worker->id);
        $this->assertDerivedProviders($event, $investigation, $action, false);
        // The test application spans requests; production aggregation is request-scoped.
        $this->app->forgetInstance(TaskAggregator::class);
        $this->get($origin)->assertOk()->assertInertia(fn (Assert $page) => $page->where('pagination.total', 0)->has('items', 0));
        $this->get('/my-day')->assertOk()->assertInertia(fn (Assert $page) => $page->has('myTasks.items', 0));
        foreach ([['hs_event', $event->id], ['hs_investigation', $investigation->id], ['corrective_action', $action->id]] as [$source, $id]) {
            $this->getJson('/tasks/detail?source='.$source.'&id='.$id)->assertNotFound();
        }
        $this->getJson('/tasks/lookup?q='.rawurlencode($action->reference_number))->assertOk()->assertJsonPath('match', null);
        $this->get($origin.'&q='.self::PRIVATE_NARRATIVE)->assertOk()->assertInertia(fn (Assert $page) => $page->where('pagination.total', 0));
        $csv = $this->get($origin.'&format=csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString(self::PRIVATE_NARRATIVE, $csv);
        $this->assertStringNotContainsString('PrivateTreatmentPerson', $csv);
        $client->supportWorkers()->attach($this->worker->id);
        $this->assertDerivedProviders($event, $investigation, $action, true);
        $this->assertNull($event->fresh()->worksafe_notifiable);
        $this->assertFalse($record->fresh()->trashed());
    }

    public function test_corrective_register_counts_copied_narrative_search_detail_and_evidence_are_intersected_with_source_authority(): void
    {
        [$client, , $event, , $action] = $this->derivedJourney();
        $attachment = $this->evidence($action);
        $this->actingAs($this->worker)->get('/health-safety/corrective-actions')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.total', 0)->where('tabCounts.all', 0)->where('tabCounts.open', 0));
        $this->get('/health-safety/corrective-actions?q='.self::PRIVATE_NARRATIVE)->assertOk()->assertInertia(fn (Assert $page) => $page->where('actions.total', 0));
        $this->get('/health-safety/events?q=PrivateTreatmentPerson')->assertOk()->assertInertia(fn (Assert $page) => $page->where('events.total', 0));
        $this->get('/health-safety/events/'.$event->id)->assertNotFound();
        $this->get('/health-safety/corrective-actions/'.$action->id)->assertNotFound();
        $this->get($this->evidenceUrl($event, $action, $attachment))->assertNotFound();
        $client->supportWorkers()->attach($this->worker->id);
        $this->get('/health-safety/corrective-actions?q='.self::PRIVATE_NARRATIVE)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.total', 1)->where('actions.data.0.id', $action->id)->where('tabCounts.all', 1));
        $this->get('/health-safety/events/'.$event->id)->assertOk();
        $this->get('/health-safety/corrective-actions/'.$action->id)->assertOk();
        $this->get($this->evidenceUrl($event, $action, $attachment))->assertOk();
        $client->supportWorkers()->detach($this->worker->id);
        $this->get($this->evidenceUrl($event, $action, $attachment))->assertNotFound();
        $this->assertTrue(Storage::disk('private')->exists($attachment->path));
    }

    public function test_named_participant_cannot_write_private_derived_work_but_restore_of_actual_client_authority_allows_independent_commands(): void
    {
        [$client, , $event, $investigation, $action] = $this->derivedJourney();
        $attachment = $this->evidence($action);
        $before = [$event->fresh()->getAttributes(), $investigation->fresh()->getAttributes(), $action->fresh()->getAttributes(), HsAttachment::count(), AuditLog::count()];
        $this->actingAs($this->worker);
        $base = '/health-safety/events/'.$event->id;
        foreach ([[$base.'/corrective-actions/'.$action->id.'/start', []], [$base.'/investigations/'.$investigation->id.'/findings', ['findings_summary' => 'This must not change private findings']], [$base.'/worksafe/decision', ['notifiable' => false, 'reason' => 'No access to this private treatment-derived event', 'source' => 'manual']]] as [$url, $payload]) {
            $this->post($url, $payload)->assertNotFound();
        }
        $this->post($this->evidenceUrl($event, $action), ['file' => UploadedFile::fake()->create('blocked.pdf', 1, 'application/pdf')])->assertNotFound();
        $this->delete($this->evidenceUrl($event, $action, $attachment))->assertNotFound();
        $this->assertSame($before, [$event->fresh()->getAttributes(), $investigation->fresh()->getAttributes(), $action->fresh()->getAttributes(), HsAttachment::count(), AuditLog::count()]);
        $this->assertTrue(Storage::disk('private')->exists($attachment->path));
        $client->supportWorkers()->attach($this->worker->id);
        $this->post($base.'/corrective-actions/'.$action->id.'/start')->assertRedirect()->assertSessionHas('success');
        $this->post($base.'/investigations/'.$investigation->id.'/findings', ['findings_summary' => 'Actual authorized factual findings'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
        $this->assertSame(HsInvestigation::STATUS_FINDINGS_RECORDED, $investigation->fresh()->status);
        $this->assertNull($event->fresh()->worksafe_notifiable);
        $this->grant($this->worker, 'hazards.manage', false);
        $this->post($base.'/corrective-actions/'.$action->id.'/complete', ['completion_notes' => 'Permission has been revoked'])->assertForbidden();
        $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
    }

    public function test_ordinary_non_treatment_participant_keeps_evidence_authority_without_global_module_grants(): void
    {
        $this->grant($this->worker, 'hazards.view', false);
        $this->grant($this->worker, 'hazards.manage', false);
        $event = HsEvent::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id]);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'assigned_to_user_id' => $this->worker->id, 'created_by' => $this->manager->id]);
        $this->actingAs($this->worker)->post($this->evidenceUrl($event, $action), ['file' => UploadedFile::fake()->create('ordinary.pdf', 1, 'application/pdf')])->assertRedirect()->assertSessionHas('success');
        $attachment = $action->attachments()->sole();
        $this->get($this->evidenceUrl($event, $action, $attachment))->assertOk();
        $this->get('/health-safety/events/'.$event->id)->assertForbidden();
        $this->delete($this->evidenceUrl($event, $action, $attachment))->assertRedirect()->assertSessionHas('success');
        $this->assertTrue($attachment->fresh()->trashed());
        $this->assertFalse(Storage::disk('private')->exists($attachment->path));
    }

    public static function invalidDerivedTuples(): array
    {
        return [['site'], ['client'], ['staff'], ['category'], ['missing_source']];
    }

    #[DataProvider('invalidDerivedTuples')]
    public function test_derived_work_requires_exact_recorded_primary_source_tuple_even_for_a_global_hs_viewer(string $change): void
    {
        [, , $event, $investigation, $action] = $this->derivedJourney(true);
        $this->grant($this->worker, 'healthSafety.viewAllSites', true);
        $this->assertDerivedProviders($event, $investigation, $action, true);
        $value = match ($change) {
            'site' => ['site_id' => Site::factory()->create(['is_active' => true])->id],
            'client' => ['client_id' => Client::factory()->create(['site_id' => $this->sourceSite->id])->id],
            'staff' => ['staff_id' => $this->firstAider->id],
            'category' => ['event_category' => HsEvent::CATEGORY_INCIDENT],
            'missing_source' => ['source_id' => 2000000000],
        };
        $event->forceFill($value)->saveQuietly();
        $this->assertDerivedProviders($event, $investigation, $action, false);
        $this->actingAs($this->worker)->get('/health-safety/events/'.$event->id)->assertNotFound();
        $this->get('/health-safety/corrective-actions/'.$action->id)->assertNotFound();
    }

    public function test_derived_provider_rechecks_staff_site_and_source_view_revocation_without_reinterpreting_other_sources(): void
    {
        [, , $event, $investigation, $action] = $this->derivedJourney(true);
        $this->assertDerivedProviders($event, $investigation, $action, true);
        $this->worker->load('permissionOverrides', 'hrEmployeeProfile');
        $this->grant($this->worker, 'hazards.view', false);
        $this->assertDerivedProviders($event, $investigation, $action, false);
        $this->grant($this->worker, 'hazards.view', true);
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertDerivedProviders($event, $investigation, $action, false);
        $this->worker->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => Site::factory()->create(['is_active' => true])->id]);
        $this->assertDerivedProviders($event, $investigation, $action, false);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        $this->assertDerivedProviders($event, $investigation, $action, true);
    }

    public function test_actual_ownership_candidates_and_assignment_require_independent_candidate_source_read_without_notifying_denied_people(): void
    {
        [$client, , , , $action] = $this->derivedJourney(true);
        $candidate = $this->sourceStaff(['hazards.view', 'hazards.manage', 'clients.viewAssigned']);
        $this->grant($candidate, 'clients.viewAny', false);
        $url = '/health-safety/corrective-actions/'.$action->id;
        $payload = ['owner_user_id' => $candidate->id, 'expected_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Actual reviewed continuity of this responsibility'];
        $notices = DB::table('notifications')->count();
        $this->actingAs($this->worker)->getJson($url.'/owner-candidates')->assertOk()->assertJsonMissing(['id' => $candidate->id]);
        $this->postJson($url.'/owner', $payload)->assertUnprocessable()->assertJsonValidationErrors('owner_user_id');
        $this->assertSame($this->worker->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
        $this->assertSame($notices, DB::table('notifications')->count());
        $client->supportWorkers()->attach($candidate->id);
        $this->getJson($url.'/owner-candidates')->assertOk()->assertJsonFragment(['id' => $candidate->id, 'name' => $candidate->name]);
        $this->post($url.'/owner', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($candidate->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame(1, (int) $action->fresh()->owner_revision);
        $this->assertSame(1, HsCorrectiveActionOwnerChange::count());
        $this->assertSame($notices + 2, DB::table('notifications')->count());
        $this->assertStringNotContainsString(self::PRIVATE_NARRATIVE, DB::table('notifications')->pluck('data')->implode(' '));
        $this->assertStringNotContainsString('PrivateTreatmentPerson', DB::table('notifications')->pluck('data')->implode(' '));
        $this->post($url.'/owner', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, HsCorrectiveActionOwnerChange::count());
        $this->assertSame($notices + 2, DB::table('notifications')->count());
    }

    public function test_private_actor_cannot_handover_and_a_revoked_outgoing_owner_receives_no_source_reference_notice(): void
    {
        [$client, , , , $action] = $this->derivedJourney(true);
        $candidate = $this->sourceStaff(['hazards.view', 'hazards.manage', 'clients.viewAssigned']);
        $this->grant($candidate, 'clients.viewAny', false);
        $client->supportWorkers()->attach($candidate->id);
        $client->supportWorkers()->detach($this->worker->id);
        $url = '/health-safety/corrective-actions/'.$action->id;
        $payload = ['owner_user_id' => $candidate->id, 'expected_revision' => 0, 'request_uuid' => (string) Str::uuid(), 'reason' => 'Recorded replacement after source access changed'];
        $before = $action->fresh()->getAttributes();
        $notices = DB::table('notifications')->count();
        $this->actingAs($this->worker)->getJson($url.'/owner-candidates')->assertForbidden();
        $this->postJson($url.'/owner', $payload)->assertForbidden();
        $this->assertSame($before, $action->fresh()->getAttributes());
        $this->assertSame(0, HsCorrectiveActionOwnerChange::count());
        $this->assertSame($notices, DB::table('notifications')->count());
        $this->actingAs($this->manager)->post($url.'/owner', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($candidate->id, $action->fresh()->assigned_to_user_id);
        $this->assertSame($notices + 1, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $this->worker->id)->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $candidate->id)->count());
        $this->assertStringNotContainsString(self::PRIVATE_NARRATIVE, DB::table('notifications')->pluck('data')->implode(' '));
    }

    public function test_source_authorization_remains_locked_through_each_provider_projection_and_actual_command_completion(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        [$client, $record, $event, $investigation, $action] = $this->derivedJourney(true);
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $config = $connection->getConfig();
        $probe = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']), $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $phase = null;
        $observed = [];
        $attempt = function (string $name) use ($probe, $record, &$observed): void {
            $probe->beginTransaction();
            try {
                $query = $probe->prepare('SELECT id FROM first_aid_records WHERE id = ? FOR UPDATE NOWAIT');
                $query->execute([$record->id]);
                $this->fail('A competing source writer must remain blocked through '.$name.'.');
            } catch (PDOException $error) {
                $this->assertSame(3572, (int) ($error->errorInfo[1] ?? 0));
                $observed[] = $name;
            } finally {
                if ($probe->inTransaction()) {
                    $probe->rollBack();
                }
            }
        };
        foreach ([HsEvent::class => $event->id, HsInvestigation::class => $investigation->id, HsCorrectiveAction::class => $action->id] as $model => $id) {
            Event::listen('eloquent.retrieved: '.$model, function ($row) use ($model, $id, &$phase, &$observed, $attempt): void {
                if ($phase === $model && (int) $row->id === (int) $id && ! in_array($model, $observed, true)) {
                    $attempt($model);
                }
            });
        }
        Event::listen('eloquent.updating: '.HsCorrectiveAction::class, function ($row) use ($action, &$phase, $attempt): void {
            if ($phase === 'command' && (int) $row->id === (int) $action->id) {
                $attempt('command');
            }
        });
        $connection->commit();
        try {
            foreach ([[HsEventProvider::class, $event], [HsInvestigationProvider::class, $investigation], [HsCorrectiveActionProvider::class, $action]] as [$provider, $row]) {
                $phase = $row::class;
                $this->assertCount(1, app($provider)->authorizedTasks($this->worker, ['id' => $row->id]));
                $phase = null;
            }
            $phase = 'command';
            $this->actingAs($this->worker)->post('/health-safety/events/'.$event->id.'/corrective-actions/'.$action->id.'/start')->assertRedirect()->assertSessionHas('success');
            $phase = null;
            $this->assertSame([HsEvent::class, HsInvestigation::class, HsCorrectiveAction::class, 'command'], $observed);
            $this->assertSame(HsCorrectiveAction::STATUS_IN_PROGRESS, $action->fresh()->status);
            // Once the operation has finished, the exact same source can be written.
            $probe->beginTransaction();
            $query = $probe->prepare('SELECT id FROM first_aid_records WHERE id = ? FOR UPDATE NOWAIT');
            $query->execute([$record->id]);
            $this->assertSame((int) $record->id, (int) $query->fetchColumn());
            $probe->rollBack();
            $client->supportWorkers()->detach($this->worker->id);
            $this->assertDerivedProviders($event, $investigation, $action, false);
        } finally {
            $phase = null;
            if ($probe->inTransaction()) {
                $probe->rollBack();
            }
            $connection->beginTransaction();
        }
    }

    public function test_actorless_first_aid_intersection_fails_closed_only_for_treatment_sources(): void
    {
        [, , $event] = $this->derivedJourney(true);
        $ordinary = HsEvent::factory()->create(['site_id' => $this->sourceSite->id, 'created_by' => $this->manager->id]);
        $ids = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query(), null)->pluck('id')->all();
        $this->assertSame([$ordinary->id], $ids);
        $this->assertNotContains($event->id, $ids);
    }
}
