<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Sites\SiteHazardController;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Notifications\Sites\HazardAssignedNotification;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use App\Services\Sites\HazardAssignmentService;
use App\Services\Tasks\Providers\SiteHazardProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class HazardLifecycleWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach (['hazards.view', ...$permissions] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'module' => 'Test', 'group' => 'test']);
            $user->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }

        return $user;
    }

    private function writer(Site $site): User
    {
        return $this->staff($site, ['sites.viewAny', 'hazards.manage', 'hazards.create', 'hazards.assign', 'hazards.close']);
    }

    private function hazard(Site $site, User $reporter, array $attributes = []): SiteHazard
    {
        return SiteHazard::withoutEvents(fn () => SiteHazard::create([
            'site_id' => $site->id, 'reported_by_user_id' => $reporter->id,
            'reference_number' => 'HZ-LIFECYCLE-'.SiteHazard::withTrashed()->count(),
            'hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare',
            'risk_rating' => 'low', 'description' => 'Synthetic loose mat.', 'status' => 'open',
            'due_date' => '2026-10-12', ...$attributes,
        ]));
    }

    public function test_closed_hazards_reject_stale_owner_review_evidence_and_edit_forms_without_writes(): void
    {
        Storage::fake('private');
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $hazard = $this->hazard($site, $writer, ['status' => 'closed', 'resolution_summary' => 'Original closure.']);
        $before = $hazard->fresh()->getAttributes();
        $auditCount = AuditLog::count();
        $this->actingAs($writer)->postJson("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $owner->id, 'due_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('assigned_to_user_id');
        $this->postJson("/hazards/{$hazard->id}/review", ['note' => 'Stale review'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->postJson("/hazards/{$hazard->id}/media", ['file' => UploadedFile::fake()->create('proof.pdf', 1)])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->putJson("/hazards/{$hazard->id}", ['description' => 'Stale correction', 'severity' => 'high', 'likelihood' => 'likely'])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->post("/hazards/{$hazard->id}/status", ['status' => 'in_progress', 'note' => 'Stale start'])->assertSessionHas('error');
        $this->assertSame($before, $hazard->fresh()->getAttributes());
        $this->assertDatabaseCount('audit_logs', $auditCount);
        $this->assertSame([], Storage::disk('private')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_competing_assignment_and_due_date_commands_require_the_current_expected_values(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $first = $this->staff($site);
        $second = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer)->postJson("/hazards/{$hazard->id}/assign", [
            'assigned_to_user_id' => $first->id, 'due_date' => '2026-11-01',
            'expected_assigned_to_user_id' => null, 'expected_due_date' => '2026-10-12',
        ])->assertRedirect();
        $stamp = $hazard->fresh()->assigned_at;
        $this->postJson("/hazards/{$hazard->id}/assign", [
            'assigned_to_user_id' => $second->id, 'due_date' => '2026-11-02',
            'expected_assigned_to_user_id' => null, 'expected_due_date' => '2026-10-12',
        ])->assertUnprocessable();
        $this->assertSame($first->id, $hazard->fresh()->assigned_to_user_id);
        $this->assertSame('2026-11-01', $hazard->fresh()->due_date->toDateString());
        $this->post("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $first->id])->assertRedirect();
        $this->assertTrue($stamp->equalTo($hazard->fresh()->assigned_at));
        $this->assertSame(1, AuditLog::where('action', 'hazard.assigned')->count());
        $this->post("/hazards/{$hazard->id}/assign", [
            'assigned_to_user_id' => $first->id, 'due_date' => null,
            'expected_assigned_to_user_id' => $first->id, 'expected_due_date' => '2026-11-01',
        ])->assertRedirect();
        $this->assertNull($hazard->fresh()->due_date);
        $changes = AuditLog::where('action', 'hazard.due_date_changed')->orderBy('id')->get();
        $this->assertSame(['2026-10-12', '2026-11-01'], $changes->pluck('meta.from')->all());
        $this->assertSame(['2026-11-01', null], $changes->pluck('meta.to')->all());
        Notification::assertSentToTimes($first, HazardAssignedNotification::class, 1);
        Notification::assertNotSentTo($second, HazardAssignedNotification::class);
    }

    public function test_a_stale_bound_model_uses_latest_status_and_appends_latest_evidence(): void
    {
        Storage::fake('private');
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer);
        $stale = $hazard->fresh();
        SiteHazard::whereKey($hazard->id)->update(['status' => 'in_progress', 'document_paths' => json_encode(['legacy/first.pdf'])]);
        $request = Request::create('/hazards/'.$hazard->id.'/status', 'POST', ['status' => 'in_progress', 'note' => 'Must not be recorded']);
        $request->setUserResolver(fn () => $writer);
        $response = app(SiteHazardController::class)->transition($request, $stale);
        $this->assertTrue(session()->has('error'));
        $this->assertSame(0, AuditLog::where('action', 'hazard.progress_note')->count());
        $request = Request::create('/hazards/'.$hazard->id.'/media', 'POST', ['kind' => 'document'], [], ['file' => UploadedFile::fake()->create('new.pdf', 1)]);
        $request->setUserResolver(fn () => $writer);
        app(SiteHazardController::class)->media($request, $stale);
        $docs = $hazard->fresh()->document_paths;
        $this->assertCount(2, $docs);
        $this->assertSame('legacy/first.pdf', $docs[0]['path']);
        $this->assertSame('new.pdf', $docs[1]['name']);
        $audit = AuditLog::where('action', 'hazard.evidence_added')->sole();
        $this->assertEquals(['kind' => 'document', 'names' => ['new.pdf'], 'count' => 1], $audit->meta);
    }

    public function test_status_timestamps_history_and_progress_notes_are_atomic_and_snapshot_original_values(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer)->post("/hazards/{$hazard->id}/status", ['status' => 'in_progress', 'note' => 'Barrier installed.'])->assertRedirect();
        $this->assertSame($writer->id, $hazard->fresh()->status_changed_by_user_id);
        $this->assertNotNull($hazard->fresh()->status_changed_at);
        $this->assertNull($hazard->fresh()->closed_at);
        $this->post("/hazards/{$hazard->id}/status", [
            'status' => 'mitigated', 'note' => 'Mat replaced.',
            'control_hierarchy' => ['isolation', 'engineering'], 'residual_severity' => 'low', 'residual_likelihood' => 'rare',
        ])->assertRedirect();
        $this->get('/hazards/'.$hazard->id)->assertOk()->assertInertia(fn ($page) => $page->where('detail.status', 'mitigated'));
        $audits = AuditLog::where('action', 'hazard.status_changed')->orderBy('id')->get();
        $this->assertSame(['open', 'in_progress'], $audits->pluck('meta.from')->all());
        $this->assertSame(['in_progress', 'mitigated'], $audits->pluck('meta.to')->all());
        $this->assertSame(['isolation', 'engineering'], $audits[1]->meta['control_hierarchy']);
        $this->assertSame('low', $audits[1]->meta['residual_risk_rating']);
        $this->assertSame(['Barrier installed.', 'Mat replaced.'], AuditLog::where('action', 'hazard.progress_note')->orderBy('id')->pluck('meta')->map(fn ($meta) => $meta['note'])->all());
        $this->assertNull($hazard->fresh()->closed_at);
    }

    public function test_failed_progress_audit_rolls_back_status_timestamps_and_semantic_history(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $hazard = $this->hazard($site, $writer);
        $before = $hazard->fresh()->getAttributes();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'hazard.progress_note') {
                throw new \RuntimeException('Synthetic history storage failure.');
            }
        });
        try {
            $this->actingAs($writer)->post("/hazards/{$hazard->id}/status", ['status' => 'in_progress', 'note' => 'Must roll back.'])->assertStatus(500);
            $this->assertSame($before, $hazard->fresh()->getAttributes());
            $this->assertSame(0, AuditLog::whereIn('action', ['hazard.status_changed', 'hazard.progress_note'])->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_rollback_does_not_deliver_assignment_notifications_or_keep_owner_history(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer);
        DB::beginTransaction();
        $hazard->update(['assigned_to_user_id' => $owner->id]);
        Notification::assertNothingSent();
        $this->assertSame(1, AuditLog::where('action', 'hazard.assigned')->count());
        DB::rollBack();
        $this->assertNull($hazard->fresh()->assigned_to_user_id);
        $this->assertSame(0, AuditLog::where('action', 'hazard.assigned')->count());
        Notification::assertNothingSent();
    }

    public function test_initial_high_risk_routing_preserves_explicit_owners_and_rejects_ineligible_officers(): void
    {
        Notification::fake();
        $this->mock(ComprehensiveAlertBridgeService::class)->shouldReceive('bridgeOperationalAlert')->andReturnNull();
        $this->mock(HsEventService::class)->shouldReceive('recordEvent')->andReturnNull();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $inactive = $this->staff($site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $remote = $this->staff(Site::factory()->create(['type' => 'house']));
        $eligible = $this->staff($site);
        $role = Role::firstOrCreate(['name' => 'health_safety_officer'], ['label' => 'H&S officer']);
        foreach ([$inactive, $remote, $eligible] as $officer) {
            $officer->roles()->attach($role->id);
        }
        $payload = ['hazard_type' => 'slip_trip_fall', 'severity' => 'high', 'likelihood' => 'likely', 'description' => 'Synthetic high risk.'];
        $this->actingAs($writer)->post("/sites/{$site->id}/hazards", [...$payload, 'assigned_to_user_id' => $writer->id])->assertRedirect();
        $explicit = SiteHazard::latest('id')->first();
        $this->assertSame($writer->id, $explicit->assigned_to_user_id);
        $this->post("/sites/{$site->id}/hazards", $payload)->assertRedirect();
        $routed = SiteHazard::latest('id')->first();
        $this->assertSame($eligible->id, $routed->assigned_to_user_id);
        Notification::assertNotSentTo($inactive, HazardAssignedNotification::class);
        Notification::assertNotSentTo($remote, HazardAssignedNotification::class);
        Notification::assertSentTo($eligible, HazardAssignedNotification::class);
    }

    public function test_initial_notification_failure_does_not_report_a_committed_hazard_as_failed(): void
    {
        $this->mock(HsEventService::class)->shouldReceive('recordEvent')->andReturnNull();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic notification failure.'));
        $this->actingAs($writer)->post("/sites/{$site->id}/hazards", [
            'hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare',
            'description' => 'Saved despite delivery failure.', 'assigned_to_user_id' => $owner->id,
        ])->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('site_hazards', 1);
        $this->assertSame($owner->id, SiteHazard::sole()->assigned_to_user_id);
        $this->assertSame(1, AuditLog::where('action', 'hazard.created')->count());
    }

    public function test_intake_rejects_forged_private_file_references(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $this->actingAs($writer)->postJson("/sites/{$site->id}/hazards", [
            'hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare',
            'description' => 'Synthetic forged evidence.', 'photo_paths' => ['hazards/foreign/photos/private.png'],
        ])->assertUnprocessable()->assertJsonValidationErrors('photo_paths');
        $this->assertDatabaseCount('site_hazards', 0);
    }

    public function test_workspace_and_all_tasks_each_send_one_assignment_notification_and_replays_send_none(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $moduleOwner = $this->staff($site);
        $queueOwner = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer)->post("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $moduleOwner->id])->assertRedirect();
        Notification::assertSentToTimes($moduleOwner, HazardAssignedNotification::class, 1);
        Notification::assertNotSentTo($moduleOwner, AppEventNotification::class);
        $this->post("/tasks/hazard/{$hazard->id}/assign", [
            'assignee_id' => $queueOwner->id, 'expected_assigned_to_user_id' => $moduleOwner->id, 'expected_due_date' => '2026-10-12',
        ])->assertSessionHas('success');
        Notification::assertSentToTimes($queueOwner, AppEventNotification::class, 1);
        Notification::assertSentTo($queueOwner, AppEventNotification::class, fn ($notification) => $notification->toArray($queueOwner)['url'] === '/hazards/'.$hazard->id);
        Notification::assertNotSentTo($queueOwner, HazardAssignedNotification::class);
        $this->post("/tasks/hazard/{$hazard->id}/assign", ['assignee_id' => $queueOwner->id])->assertSessionHas('success');
        $this->post("/hazards/{$hazard->id}/assign", ['assigned_to_user_id' => $queueOwner->id, 'due_date' => '2026-10-13'])->assertRedirect();
        Notification::assertSentToTimes($queueOwner, AppEventNotification::class, 1);
        Notification::assertNotSentTo($queueOwner, HazardAssignedNotification::class);
        $this->assertSame(2, AuditLog::where('action', 'hazard.assigned')->count());
        $this->assertSame($queueOwner->id, $hazard->fresh()->assigned_to_user_id);
    }

    public function test_all_tasks_rejects_stale_foreign_inactive_closed_and_unassign_commands_without_delivery(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $remote = $this->staff(Site::factory()->create(['type' => 'house']));
        $inactive = $this->staff($site);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $hazard = $this->hazard($site, $writer, ['assigned_to_user_id' => $owner->id]);
        $before = $hazard->fresh()->getAttributes();
        $this->actingAs($writer)->post("/tasks/hazard/{$hazard->id}/assign", [
            'assignee_id' => $owner->id, 'expected_assigned_to_user_id' => null, 'expected_due_date' => '2026-10-12',
        ])->assertSessionHas('error');
        foreach ([$remote->id, $inactive->id, null] as $rejected) {
            $this->post("/tasks/hazard/{$hazard->id}/assign", ['assignee_id' => $rejected])->assertSessionHas('error');
        }
        $this->assertSame($before, $hazard->fresh()->getAttributes());
        $hazard->forceFill(['status' => 'closed'])->saveQuietly();
        $this->post("/tasks/hazard/{$hazard->id}/assign", ['assignee_id' => $owner->id])->assertSessionHas('error');
        $this->assertSame(0, AuditLog::where('action', 'hazard.assigned')->count());
        Notification::assertNothingSent();
    }

    public function test_assignment_rechecks_cached_site_scope_and_current_permission_evidence(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer);
        $this->assertNotNull(app(TaskAggregator::class)->findItemFor($writer, 'hazard', $hazard->id));
        $writer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['type' => 'house'])->id]);
        try {
            app(HazardAssignmentService::class)->assign($writer, $hazard->id, $owner->id);
            $this->fail('Cached Site access must not authorize assignment.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $writer->hrEmployeeProfile()->update(['primary_site_id' => $site->id]);
        $writer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'hazards.assign')->value('id'), ['allowed' => false]);
        try {
            app(HazardAssignmentService::class)->assign($writer, $hazard->id, $owner->id);
            $this->fail('Cached permission must not authorize assignment.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertNull($hazard->fresh()->assigned_to_user_id);
        Notification::assertNothingSent();
    }

    public function test_lifecycle_command_rechecks_site_access_after_the_bound_record_was_loaded(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer);
        $stale = $hazard->fresh();
        $this->assertTrue($writer->can('view', $site));
        $writer->hrEmployeeProfile()->update(['primary_site_id' => Site::factory()->create(['type' => 'house'])->id]);
        $request = Request::create('/hazards/'.$hazard->id.'/status', 'POST', ['status' => 'in_progress', 'note' => 'Revoked access must not write']);
        $request->setUserResolver(fn () => $writer);
        try {
            app(SiteHazardController::class)->transition($request, $stale);
            $this->fail('Cached Site access must not authorize lifecycle writes.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame('open', $hazard->fresh()->status);
        $this->assertSame(0, AuditLog::where('action', 'hazard.progress_note')->count());
    }

    public function test_initial_and_action_assignments_recheck_lost_secondary_site_and_current_candidate_employment(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $otherSite = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $writer->hrEmployeeProfile()->update(['secondary_site_ids' => [$otherSite->id]]);
        $remote = $this->staff($otherSite);
        $inactive = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer);
        $this->get('/hazards/'.$hazard->id)->assertOk();
        $this->assertTrue(app(UserSiteAccessService::class)->applyStaffScope(User::query()->whereKey($remote->id), $writer, ['sites.viewAll'])->exists());
        $this->assertTrue(app(UserSiteAccessService::class)->applyStaffScope(User::query()->whereKey($inactive->id), $writer, ['sites.viewAll'])->exists());
        $writer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        foreach ([$remote, $inactive] as $rejected) {
            $this->postJson('/hazards/'.$hazard->id.'/actions', ['title' => 'Rejected candidate', 'assigned_to_user_id' => $rejected->id])->assertUnprocessable()->assertJsonValidationErrors('assigned_to_user_id');
            $this->postJson('/sites/'.$site->id.'/hazards', [
                'hazard_type' => 'slip_trip_fall', 'severity' => 'low', 'likelihood' => 'rare',
                'description' => 'Rejected initial candidate', 'assigned_to_user_id' => $rejected->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('assigned_to_user_id');
        }
        $this->assertDatabaseCount('site_hazards', 1);
        $this->assertSame(0, $hazard->actions()->count());
        Notification::assertNothingSent();
    }

    public function test_all_tasks_assignment_audit_failure_rolls_back_without_any_notification(): void
    {
        Notification::fake();
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === 'hazard.assigned') {
                throw new \RuntimeException('Synthetic assignment audit failure.');
            }
        });
        try {
            $this->actingAs($writer)->post("/tasks/hazard/{$hazard->id}/assign", ['assignee_id' => $owner->id])->assertStatus(500);
            $this->assertNull($hazard->fresh()->assigned_to_user_id);
            $this->assertNull($hazard->fresh()->assigned_at);
            $this->assertSame(0, AuditLog::where('action', 'hazard.assigned')->count());
            Notification::assertNothingSent();
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_hazard_tasks_keep_mitigated_work_active_and_calendar_due_states_truthful(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-08 00:30:00', 'Pacific/Auckland')->utc());
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $today = $this->hazard($site, $writer, ['due_date' => '2026-10-08']);
        $overdue = $this->hazard($site, $writer, ['due_date' => '2026-10-07']);
        $mitigated = $this->hazard($site, $writer, ['due_date' => '2026-10-07', 'status' => 'mitigated']);
        $closed = $this->hazard($site, $writer, ['due_date' => '2026-10-07', 'status' => 'closed']);
        $items = collect(app(SiteHazardProvider::class)->authorizedTasks($writer))->keyBy('id');
        $this->assertFalse($items['hazard-'.$today->id]->isOverdue());
        $this->assertTrue($items['hazard-'.$overdue->id]->isOverdue());
        $this->assertFalse($items['hazard-'.$mitigated->id]->isOverdue());
        $this->assertSame('in_progress', $items['hazard-'.$mitigated->id]->bucket);
        $this->assertSame('Awaiting closure', $items['hazard-'.$mitigated->id]->displayState);
        $this->assertSame('2026-10-08', substr($items['hazard-'.$today->id]->dueAt, 0, 10));
        $this->assertFalse($items->has('hazard-'.$closed->id));
        $closedItem = app(TaskAggregator::class)->findItemFor($writer, 'hazard', $closed->id);
        $this->assertSame('done', $closedItem->bucket);
        $this->assertFalse($closedItem->isOverdue());
    }

    public function test_exact_hazard_task_resolution_is_not_lost_after_three_hundred_newer_rows(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $old = $this->hazard($site, $writer, ['created_at' => now()->subDays(2)]);
        $attributes = $old->fresh()->getAttributes();
        unset($attributes['id']);
        $rows = [];
        for ($index = 0; $index < 305; $index++) {
            $rows[] = [...$attributes, 'reference_number' => 'HZ-LIFECYCLE-BULK-'.$index, 'created_at' => now()->toDateTimeString()];
        }
        DB::table('site_hazards')->insert($rows);
        $item = app(TaskAggregator::class)->findItemFor($writer, 'hazard', $old->id);
        $this->assertNotNull($item);
        $this->assertSame('hazard-'.$old->id, $item->id);
        $this->assertSame('/hazards/'.$old->id, $item->link);
        $this->actingAs($writer)->get('/tasks/detail?source=hazard&id='.$old->id)->assertOk()->assertJsonPath('item.id', $item->id);
    }

    public function test_source_action_creation_records_the_creator_separately_from_its_executor(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $executor = $this->staff($site);
        $hazard = $this->hazard($site, $writer);
        $this->actingAs($writer)->post("/hazards/{$hazard->id}/actions", ['title' => 'Synthetic repair', 'assigned_to_user_id' => $executor->id])->assertRedirect();
        $action = $hazard->actions()->sole();
        $audit = AuditLog::where('action', 'hazard.action_added')->sole();
        $this->assertSame($writer->id, $audit->user_id);
        $this->assertSame($action->id, $audit->meta['action_id']);
        $this->assertSame($executor->id, $audit->meta['assigned_to_user_id']);
        $this->get('/hazards/'.$hazard->id)->assertInertia(fn ($page) => $page->where('detail.history', fn ($entries) => collect($entries)->filter(fn ($entry) => $entry['type'] === 'action_added')->count() === 1
            && collect($entries)->contains(fn ($entry) => $entry['type'] === 'action_added' && $entry['actor'] === $writer->name)));
    }

    public function test_source_action_completion_preserves_hazard_owner_and_independent_closure(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $writer = $this->writer($site);
        $owner = $this->staff($site);
        $hazard = $this->hazard($site, $writer, ['assigned_to_user_id' => $owner->id]);
        $action = SiteHazardAction::create(['hazard_id' => $hazard->id, 'reference_number' => 'HZA-LIFECYCLE', 'action_description' => 'Secure mat', 'status' => 'pending', 'assigned_to_user_id' => $writer->id]);
        $this->actingAs($writer)->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Premature closure'])->assertSessionHas('error');
        $this->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Mat secured'])->assertRedirect();
        $this->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Replay must not change notes'])->assertRedirect();
        $this->assertSame('Mat secured', $action->fresh()->completion_notes);
        $this->assertSame('open', $hazard->fresh()->status);
        $this->assertSame($owner->id, $hazard->fresh()->assigned_to_user_id);
        $this->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Verified safe'])->assertRedirect();
        $this->assertSame('closed', $hazard->fresh()->status);
        $this->assertSame($owner->id, $hazard->fresh()->assigned_to_user_id);
        $this->assertSame($writer->id, $hazard->fresh()->closed_by_user_id);
        $audit = AuditLog::where('action', 'hazard.status_changed')->sole();
        $this->assertSame('Verified safe', $audit->meta['resolution_summary']);
    }
}
