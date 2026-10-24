<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\EmergencyDrill;
use App\Models\EmergencyDrillAttachment;
use App\Models\EmergencyDrillFailureDelivery;
use App\Models\EmergencyDrillFinding;
use App\Models\EmergencyDrillParticipant;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\EmergencyDrillConnectionsService;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\EmergencyDrillPresenter;
use App\Support\HealthSafety\EmergencyDrillReturn;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmergencyDrillWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    private array $hiddenActions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->manager = $this->worker(true);
        $tasks = Mockery::mock(TaskAggregator::class);
        $tasks->shouldReceive('navigationBadgeFor')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('navigationBadgeInContext')->andReturn(['view' => true, 'badge' => 0, 'degraded' => false]);
        $tasks->shouldReceive('findItemFor')->andReturnUsing(function ($viewer, $source, $id) {
            if ($source !== 'corrective_action' || in_array((int) $id, $this->hiddenActions, true)) {
                return null;
            }
            $action = HsCorrectiveAction::find($id);

            return $action ? new TaskItem(id: 'corrective_action-'.$id, source: 'corrective_action', sourceLabel: 'Corrective actions',
                ref: $action->reference_number, title: $action->title, status: $action->status, bucket: TaskItem::BUCKET_OPEN,
                severity: 'medium', assignee: null, link: '/health-safety/corrective-actions/'.$id.'?section=overview') : null;
        });
        $this->app->instance(TaskAggregator::class, $tasks);
    }

    public function test_permanent_html_and_json_clients_share_scoped_detail_and_bounded_legacy_redirect(): void
    {
        $drill = $this->drill();
        $origin = '/health-safety/drills?tab=scheduled&q=fire&site_id='.$this->site->id;
        $href = EmergencyDrillReturn::href($drill->id, $origin, 'participants');
        $this->actingAs($this->manager)->get($href)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('health-safety/drills/show')->where('detail.id', $drill->id)->where('return_to', $origin));
        $this->getJson('/health-safety/drills/'.$drill->id)->assertOk()->assertJsonPath('detail.id', $drill->id);
        $this->get('/health-safety/drills?drill='.$drill->id.'&tab=scheduled&q=fire&site_id='.$this->site->id)
            ->assertRedirect(EmergencyDrillReturn::href($drill->id, $origin));
        $foreign = $this->drill(['site_id' => Site::factory()->create()->id]);
        $this->get('/health-safety/drills/'.$foreign->id)->assertNotFound();
        $this->get('/health-safety/drills?drill='.$foreign->id)->assertNotFound();
        $this->get('/health-safety/drills?drill[]=1')->assertNotFound();
    }

    public function test_source_counts_preserve_actual_attendance_and_participant_support_notes(): void
    {
        $drill = $this->drill(['status' => 'completed', 'completed_at' => now(), 'total_participants' => 9, 'residents_evacuated' => 2, 'roll_call_completed' => true]);
        $one = $this->worker();
        $two = $this->worker();
        EmergencyDrillParticipant::create(['emergency_drill_id' => $drill->id, 'user_id' => $one->id, 'role' => 'warden', 'attended' => true, 'notes' => 'Recorded attendance', 'accommodations' => 'Visual instruction support']);
        EmergencyDrillParticipant::create(['emergency_drill_id' => $drill->id, 'user_id' => $two->id, 'role' => 'observer', 'attended' => false, 'notes' => 'Absent', 'accommodations' => null]);
        $detail = $this->detail($drill);
        $this->assertSame(['assigned' => 2, 'attended' => 1, 'not_attended' => 1, 'total_recorded' => 9, 'residents_evacuated' => 2, 'findings_open' => 0, 'findings_overdue' => 0], $detail['counts']);
        $this->assertSame('Visual instruction support', $detail['participants'][0]['accommodations']);
        $this->assertSame('Absent', $detail['participants'][1]['notes']);
        $this->assertSame('1 attended', app(EmergencyDrillPresenter::class)->row($drill, $this->manager)['people_label']);
        $this->assertArrayNotHasKey('all_residents_evacuated', $detail);
        $this->assertFalse($detail['can']['add_participant']);
    }

    public function test_read_schedule_and_manage_authority_are_independent_and_terminal_commands_truthful(): void
    {
        $drill = $this->drill();
        $reader = $this->worker(false, true);
        $can = $this->detail($drill, $reader)['can'];
        $this->assertTrue($can['view']);
        $this->assertFalse($can['manage']);
        $this->assertFalse($can['start']);
        $this->assertTrue($can['export_evidence']);
        $drill->forceFill(['status' => 'completed', 'completed_at' => now()])->saveQuietly();
        $can = $this->detail($drill)['can'];
        foreach (['edit', 'start', 'complete', 'cancel', 'add_participant', 'remove_evidence'] as $key) {
            $this->assertFalse($can[$key], $key);
        }
        $this->assertTrue($can['schedule_next']);
        $this->assertTrue($can['add_finding']);
        $drill->forceFill(['status' => 'cancelled'])->saveQuietly();
        $can = $this->detail($drill)['can'];
        $this->assertFalse($can['add_evidence']);
        $this->assertFalse($can['add_finding']);
        $this->assertTrue($can['schedule_next']);
    }

    public function test_current_permission_staff_and_site_changes_override_loaded_viewer(): void
    {
        $drill = $this->drill();
        $this->assertSame($drill->id, $this->detail($drill)['id']);
        $permission = Permission::where('key', 'hazards.view')->firstOrFail();
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertDenied(fn () => $this->detail($drill), 403);
        $this->manager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->manager->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertDenied(fn () => $this->detail($drill), 403);
        $this->manager->hrEmployeeProfile()->update(['is_active' => true, 'primary_site_id' => Site::factory()->create()->id]);
        $this->assertDenied(fn () => $this->detail($drill), 404);
    }

    public function test_findings_use_nz_calendar_days_and_preserve_legacy_terminal_labels(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08T01:00:00+13:00')->utc());
        $drill = $this->drill(['scheduled_at' => now()->subMinute()]);
        $today = $this->finding($drill, ['due_date' => '2026-10-08']);
        $yesterday = $this->finding($drill, ['due_date' => '2026-10-07']);
        foreach (['accepted', 'closed', 'resolved'] as $status) {
            $this->finding($drill, ['status' => $status, 'due_date' => '2026-10-01']);
        }
        $detail = $this->detail($drill);
        $this->assertFalse(collect($detail['findings'])->firstWhere('id', $today->id)['is_overdue']);
        $this->assertTrue(collect($detail['findings'])->firstWhere('id', $yesterday->id)['is_overdue']);
        $this->assertSame(2, $detail['counts']['findings_open']);
        $this->assertSame(1, $detail['counts']['findings_overdue']);
        $this->assertSame('overdue', app(EmergencyDrillPresenter::class)->row($drill, $this->manager)['status']);
    }

    public function test_only_exact_authorized_source_reviews_and_task_readable_actions_are_projected_without_limit(): void
    {
        $drill = $this->drill();
        $events = [];
        for ($i = 0; $i < 23; $i++) {
            $events[] = $this->event($drill);
        }
        $visible = HsCorrectiveAction::factory()->create(['hs_event_id' => $events[0]->id, 'title' => 'Actual remedy']);
        $hidden = HsCorrectiveAction::factory()->create(['hs_event_id' => $events[0]->id, 'title' => 'Hidden remedy']);
        $this->hiddenActions[] = $hidden->id;
        $wrong = $this->event($this->drill());
        HsCorrectiveAction::factory()->create(['hs_event_id' => $wrong->id, 'title' => 'Other drill secret']);
        $this->event($drill, ['site_id' => Site::factory()->create()->id, 'closure_summary' => 'Wrong Site secret']);
        $this->event($drill, ['event_category' => HsEvent::CATEGORY_HAZARD, 'closure_summary' => 'Wrong category secret']);
        $links = app(EmergencyDrillConnectionsService::class)->forViewer($drill, $this->manager, '/my-day');
        $this->assertCount(23, $links['safety_reviews']);
        $this->assertSame([$visible->id], array_column($links['corrective_actions'], 'id'));
        $this->assertStringNotContainsString('secret', json_encode($links));
        $this->assertStringNotContainsString('Hidden remedy', json_encode($links));
        parse_str(parse_url($links['corrective_actions'][0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame('overview', $query['section']);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/my-day', 'connections'), $query['return_to']);
    }

    public function test_required_remedy_verification_and_supporting_evidence_are_separate_from_drill_completion(): void
    {
        $drill = $this->drill(['status' => 'completed', 'completed_at' => now(), 'outcome' => 'passed']);
        $finding = $this->finding($drill);
        $action = HsCorrectiveAction::factory()->create(['hs_event_id' => $this->event($drill)->id, 'status' => 'verified', 'effectiveness_confirmed' => false]);
        $finding->forceFill(['hs_corrective_action_id' => $action->id])->saveQuietly();
        $evidence = $this->attachment($drill);
        $row = $this->detail($drill)['findings'][0];
        $this->assertFalse($row['can']['resolve']);
        $this->assertSame('open', $row['status']);
        $this->assertFalse($row['corrective_action_record']['effectiveness_confirmed']);
        $action->forceFill(['effectiveness_confirmed' => true])->saveQuietly();
        $row = $this->detail($drill)['findings'][0];
        $this->assertTrue($row['can']['resolve']);
        parse_str(parse_url($row['corrective_action_record']['href'], PHP_URL_QUERY), $query);
        $this->assertSame(EmergencyDrillReturn::href($drill->id, '/health-safety/drills', 'findings', $finding->id), $query['return_to']);
        $evidence->delete();
        $this->assertFalse($this->detail($drill)['findings'][0]['can']['resolve']);
        $this->assertSame('open', $finding->refresh()->status);
        $this->assertSame('verified', $action->refresh()->status);
    }

    public function test_foreign_remedy_and_private_evidence_identifiers_are_never_projected_as_local_proof(): void
    {
        $drill = $this->drill();
        $foreignDrill = $this->drill(['site_id' => Site::factory()->create()->id]);
        $foreignEvidence = $this->attachment($foreignDrill, ['original_name' => 'Hidden private evidence.pdf']);
        $action = HsCorrectiveAction::factory()->verified()->create(['hs_event_id' => $this->event($foreignDrill)->id]);
        $finding = $this->finding($drill);
        $finding->forceFill(['hs_corrective_action_id' => $action->id, 'resolution_evidence_ids' => [$foreignEvidence->id]])->saveQuietly();
        $detail = $this->detail($drill);
        $this->assertNull($detail['findings'][0]['corrective_action_record']);
        $this->assertFalse($detail['findings'][0]['can']['resolve']);
        $this->assertSame([], $detail['findings'][0]['resolution_evidence_ids']);
        $this->assertSame([], $detail['attachments']);
        $this->assertStringNotContainsString('Hidden private evidence', json_encode($detail));
    }

    public function test_resolution_evidence_retention_suppresses_removal_and_private_paths_are_absent(): void
    {
        $drill = $this->drill();
        $file = $this->attachment($drill, ['original_name' => 'C:\\private\\sign-in.pdf', 'path' => 'private/drill/source-secret.pdf']);
        $finding = $this->finding($drill, ['status' => 'resolved']);
        $finding->forceFill(['resolution_evidence_ids' => [$file->id], 'resolution_notes' => 'Recorded local proof'])->saveQuietly();
        $detail = $this->detail($drill);
        $this->assertSame('sign-in.pdf', $detail['attachments'][0]['original_name']);
        $this->assertFalse($detail['attachments'][0]['can']['remove']);
        $this->assertSame([$file->id], $detail['findings'][0]['resolution_evidence_ids']);
        $this->assertStringNotContainsString('source-secret', json_encode($detail));
        $this->assertArrayNotHasKey('disk', $detail['attachments'][0]);
        $this->assertArrayNotHasKey('path', $detail['attachments'][0]);
    }

    public function test_semantic_history_is_immutable_allowlisted_and_legacy_does_not_invent_notes_or_actor(): void
    {
        $drill = $this->drill(['observer_notes' => 'Current changed notes']);
        $finding = $this->finding($drill, ['description' => 'Later edited finding']);
        $audit = AuditLog::create(['user_id' => $this->manager->id, 'action' => 'drill.finding_resolved', 'auditable_type' => $drill->getMorphClass(), 'auditable_id' => $drill->id,
            'meta' => ['finding_id' => $finding->id, 'note' => 'Original resolution', 'snapshot' => ['observer_notes' => 'Original observed notes', 'all_areas_checked' => false, 'private_path' => 'hidden-storage-path'],
                'finding_snapshot' => ['description' => 'Original finding', 'status' => 'resolved', 'assigned_to' => 999, 'private_path' => 'hidden-storage-path'], 'recipient_email' => 'private@example.test']]);
        $legacy = AuditLog::create(['user_id' => null, 'action' => 'emergencydrill.update', 'auditable_type' => $drill->getMorphClass(), 'auditable_id' => $drill->id,
            'meta' => ['notes' => 'Legacy private or truncated notes', 'changes' => ['path' => 'hidden-storage-path']]]);
        $detail = $this->detail($drill);
        $row = collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertSame($this->manager->id, $row['actor']['id']);
        $this->assertSame('Original resolution', $row['note']);
        $this->assertSame('Original observed notes', $row['snapshot']['observer_notes']);
        $this->assertFalse($row['snapshot']['all_areas_checked']);
        $this->assertSame('Original finding', $row['snapshot']['finding']['description']);
        $this->assertArrayNotHasKey('assigned_to', $row['snapshot']['finding']);
        $old = collect($detail['history'])->firstWhere('id', 'audit-'.$legacy->id);
        $this->assertTrue($old['legacy']);
        $this->assertNull($old['actor']);
        $this->assertNull($old['note']);
        $this->assertSame([], $old['snapshot']);
        $this->assertStringNotContainsString('hidden-storage-path', json_encode($detail));
        $this->assertStringNotContainsString('private@example', json_encode($detail));
    }

    public function test_next_schedule_links_are_explicit_same_site_and_exclude_foreign_or_cyclic_records(): void
    {
        $previous = $this->drill(['status' => 'completed']);
        $drill = $this->drill(['previous_drill_id' => $previous->id]);
        $next = $this->drill(['previous_drill_id' => $drill->id]);
        $this->drill(['previous_drill_id' => $drill->id, 'site_id' => Site::factory()->create()->id]);
        $links = app(EmergencyDrillConnectionsService::class)->forViewer($drill, $this->manager, '/sites/'.$this->site->id.'?tab=drills');
        $this->assertSame($previous->id, $links['previous_drill']['id']);
        $this->assertSame([$next->id], array_column($links['next_drills'], 'id'));
        $this->assertSame(EmergencyDrillReturn::href($next->id, '/sites/'.$this->site->id.'?tab=drills'), $links['next_drills'][0]['href']);
        $previous->forceFill(['previous_drill_id' => $drill->id])->saveQuietly();
        $this->assertNull(app(EmergencyDrillConnectionsService::class)->forViewer($drill, $this->manager)['previous_drill']);
    }

    public function test_delivery_reports_actual_independent_stages_without_private_transport_or_response_claims(): void
    {
        $drill = $this->drill(['status' => 'completed', 'completed_at' => now(), 'outcome' => 'failed']);
        $delivery = EmergencyDrillFailureDelivery::create(['emergency_drill_id' => $drill->id, 'event_status' => 'delivered', 'signal_status' => 'failed',
            'event_delivered_at' => now(), 'attempts' => 2, 'failure_code' => 'signal_delivery_failed', 'next_attempt_at' => now()->addMinute(), 'signal_key' => 'private-source-key']);
        $detail = $this->detail($drill);
        $this->assertSame('delivered', $detail['source_delivery']['event_status']);
        $this->assertSame('failed', $detail['source_delivery']['signal_status']);
        $this->assertSame(2, $detail['source_delivery']['attempts']);
        $this->assertSame('signal_delivery_failed', $detail['source_delivery']['failure_code']);
        $this->assertSame([], $detail['connections']['control_room']);
        $this->assertStringNotContainsString('private-source-key', json_encode($detail));
        $this->assertArrayNotHasKey('notified', $detail['source_delivery']);
        $delivery->forceFill(['signal_status' => 'suppressed', 'failure_code' => 'Private raw transport exception'])->save();
        $summary = $this->detail($drill)['source_delivery'];
        $this->assertSame('suppressed', $summary['signal_status']);
        $this->assertNull($summary['failure_code']);
    }

    private function worker(bool $manage = false, bool $create = false): User
    {
        $user = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        foreach (['hazards.view' => true, 'hazards.manage' => $manage, 'hazards.create' => $create, 'healthSafety.viewAllSites' => false] as $key => $allowed) {
            $permission = Permission::where('key', $key)->firstOrFail();
            $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    private function drill(array $fields = []): EmergencyDrill
    {
        $drill = EmergencyDrill::factory()->create(['site_id' => $this->site->id, 'created_by' => $this->manager->id]);
        $drill->forceFill(['lock_version' => 1, ...$fields])->saveQuietly();

        return $drill;
    }

    private function finding(EmergencyDrill $drill, array $fields = []): EmergencyDrillFinding
    {
        return EmergencyDrillFinding::create(['emergency_drill_id' => $drill->id, 'finding_type' => 'observation', 'description' => 'Recorded observation', 'severity' => 'medium', 'status' => 'open', 'created_by' => $this->manager->id, ...$fields]);
    }

    private function attachment(EmergencyDrill $drill, array $fields = []): EmergencyDrillAttachment
    {
        $file = EmergencyDrillAttachment::create(['emergency_drill_id' => $drill->id, 'uploaded_by' => $this->manager->id, 'disk' => 'private', 'path' => 'private/support.pdf', 'original_name' => 'support.pdf', 'mime' => 'application/pdf', 'size' => 40, 'kind' => 'document', ...$fields]);
        Storage::disk('private')->put($file->path, 'actual supporting evidence');

        return $file;
    }

    private function event(EmergencyDrill $drill, array $fields = []): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $drill->site_id, 'source_type' => EmergencyDrill::class, 'source_id' => $drill->id, 'event_category' => HsEvent::CATEGORY_DRILL_FAILURE, ...$fields]);
    }

    private function detail(EmergencyDrill $drill, ?User $viewer = null): array
    {
        return app(EmergencyDrillPresenter::class)->detail($drill, $viewer ?? $this->manager, '/health-safety/drills');
    }

    private function assertDenied(callable $operation, int $status): void
    {
        try {
            $operation();
            $this->fail('Expected current authorization denial.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        }
    }
}
