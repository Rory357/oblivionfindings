<?php

namespace Tests\Feature\Checklists;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\User;
use App\Services\Sites\SiteChecklistRunExecutionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ChecklistRunOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_peer_cannot_edit_or_complete_an_assigned_run_and_owner_signature_is_verifiable(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        $peer = $this->siteUser('support_worker', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);
        $payload = $this->payload($item, 'yes');

        $this->actingAs($peer)
            ->get("/sites/{$site->id}/checklists?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeRuns.0.can_run', false)
                ->where('runDetail.can_run', false));
        $this->actingAs($owner)
            ->get("/sites/{$site->id}/checklists?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('activeRuns.0.can_run', true)
                ->where('runDetail.can_run', true));

        $this->actingAs($peer)
            ->post("/checklists/runs/{$run->id}/responses", ['responses' => $payload])
            ->assertForbidden();
        $this->actingAs($peer)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $payload,
                'signature_name' => 'Peer Worker',
            ])
            ->assertForbidden();

        $this->assertSame('in_progress', $run->fresh()->status);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Checklist Ownership Test Agent')
            ->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $payload,
                'signature_name' => 'Typed Owner Attestation',
                'overall_notes' => 'All checks completed.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Checklist completed.');

        $completed = $run->fresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame($owner->id, $completed->completed_by_user_id);
        $this->assertSame('Typed Owner Attestation', $completed->signature_name);
        $this->assertSame('203.0.113.9', $completed->signature_ip_address);
        $this->assertSame('Checklist Ownership Test Agent', $completed->signature_user_agent);
        $this->assertSame(SiteChecklistRunExecutionService::AUTHORITY_RUN_ASSIGNEE, $completed->completion_authority);
        $this->assertNull($completed->completion_authority_reason);
        $this->assertNotNull($completed->signature_signed_at);
        $this->assertTrue($completed->hasVerifiableSignatureProvenance());

        $completionAudit = AuditLog::query()
            ->where('action', 'checklist.completed')
            ->where('auditable_id', $run->id)
            ->sole();
        $this->assertSame($owner->id, $completionAudit->user_id);
        $this->assertSame('Typed Owner Attestation', $completionAudit->meta['signature_name'] ?? null);
        $this->assertSame($completed->signature_payload_hash, $completionAudit->meta['signature_payload_hash'] ?? null);

        $completed->responses()->where('template_item_id', $item->id)->update(['response_value' => 'tampered']);
        $this->assertFalse($completed->fresh()->hasVerifiableSignatureProvenance());
    }

    public function test_assignment_owner_is_authoritative_and_an_unassigned_run_is_claimed_on_first_save(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $assignmentOwner = $this->siteUser('support_worker', $site);
        [$assignedRun, $assignedItem] = $this->makeChecklistRun($site, assignmentAssignee: $assignmentOwner);

        $this->actingAs($assignmentOwner)
            ->post("/checklists/runs/{$assignedRun->id}/complete", [
                'responses' => $this->payload($assignedItem, 'yes'),
                'signature_name' => 'Assignment Owner',
            ])
            ->assertRedirect();

        $this->assertSame(
            SiteChecklistRunExecutionService::AUTHORITY_ASSIGNMENT_ASSIGNEE,
            $assignedRun->fresh()->completion_authority,
        );

        $claimant = $this->siteUser('support_worker', $site);
        [$unassignedRun, $unassignedItem] = $this->makeChecklistRun($site);
        $this->actingAs($claimant)
            ->post("/checklists/runs/{$unassignedRun->id}/responses", [
                'responses' => $this->payload($unassignedItem, 'yes'),
            ])
            ->assertRedirect();

        $this->assertSame($claimant->id, $unassignedRun->fresh()->assigned_to_user_id);

        $this->actingAs($claimant)
            ->post("/checklists/runs/{$unassignedRun->id}/complete", [
                'responses' => $this->payload($unassignedItem, 'yes'),
                'signature_name' => 'Claiming Worker',
            ])
            ->assertRedirect();

        $this->assertSame(
            SiteChecklistRunExecutionService::AUTHORITY_RUN_ASSIGNEE,
            $unassignedRun->fresh()->completion_authority,
        );
    }

    public function test_wrong_site_direct_ids_are_concealed_without_side_effects(): void
    {
        $visibleSite = Site::factory()->create(['type' => 'house']);
        $hiddenSite = Site::factory()->create(['type' => 'house']);
        $actor = $this->siteUser('support_worker', $visibleSite);
        $hiddenOwner = $this->siteUser('support_worker', $hiddenSite);
        [$run, $item] = $this->makeChecklistRun($hiddenSite, runAssignee: $hiddenOwner);

        $this->actingAs($actor)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->payload($item, 'yes'),
            ])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Foreign Actor',
            ])
            ->assertNotFound();

        SiteChecklistRun::query()->whereKey($run->id)->update(['site_id' => $visibleSite->id]);
        $this->actingAs($actor)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->payload($item, 'yes'),
            ])
            ->assertNotFound();

        $this->assertSame('in_progress', $run->fresh()->status);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'checklist.completed',
            'auditable_id' => $run->id,
        ]);
    }

    public function test_explicit_global_manager_override_is_retained_with_reason(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        $admin = $this->roleUser('admin');
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);

        $this->assertTrue($admin->canDo('sites.viewAll'));
        $this->assertTrue($admin->canDo('checklists.schedule'));
        $this->assertFalse($admin->hrEmployeeProfile()->exists());

        $this->actingAs($admin)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->payload($item, 'yes'),
            ])
            ->assertRedirect();
        $saveAudit = AuditLog::query()
            ->where('action', 'checklist.responses.saved')
            ->where('auditable_id', $run->id)
            ->sole();
        $this->assertSame($admin->id, $saveAudit->user_id);
        $this->assertSame(
            SiteChecklistRunExecutionService::AUTHORITY_MANAGER_OVERRIDE,
            $saveAudit->meta['execution_authority'] ?? null,
        );
        $this->assertSame(
            SiteChecklistRunExecutionService::MANAGER_OVERRIDE_REASON,
            $saveAudit->meta['execution_authority_reason'] ?? null,
        );

        $this->actingAs($admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Global Operations Manager',
            ])
            ->assertRedirect();

        $completed = $run->fresh();
        $this->assertSame(SiteChecklistRunExecutionService::AUTHORITY_MANAGER_OVERRIDE, $completed->completion_authority);
        $this->assertSame(
            SiteChecklistRunExecutionService::MANAGER_OVERRIDE_REASON,
            $completed->completion_authority_reason,
        );
        $this->assertSame($admin->id, $completed->completed_by_user_id);
        $this->assertTrue($completed->hasVerifiableSignatureProvenance());
    }

    public function test_manager_handover_requires_a_site_authorized_recipient_and_transfers_ownership(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $foreignSite = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        $replacement = $this->siteUser('support_worker', $site);
        $foreignRecipient = $this->siteUser('support_worker', $foreignSite);
        $manager = $this->siteUser('team_lead', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);

        $this->actingAs($manager)
            ->patch("/checklists/runs/{$run->id}/assign", [
                'assigned_to_user_id' => $foreignRecipient->id,
            ])
            ->assertSessionHasErrors('assigned_to_user_id');
        $this->assertSame($owner->id, $run->fresh()->assigned_to_user_id);

        $this->actingAs($manager)
            ->patch("/checklists/runs/{$run->id}/assign", [
                'assigned_to_user_id' => $replacement->id,
            ])
            ->assertRedirect();
        $this->assertSame($replacement->id, $run->fresh()->assigned_to_user_id);
        $handoverAudit = AuditLog::query()
            ->where('action', 'checklist.reassigned')
            ->where('auditable_id', $run->id)
            ->sole();
        $this->assertSame($manager->id, $handoverAudit->user_id);
        $this->assertSame($owner->id, $handoverAudit->meta['from_user_id'] ?? null);
        $this->assertSame($replacement->id, $handoverAudit->meta['to_user_id'] ?? null);

        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->payload($item, 'yes'),
            ])
            ->assertForbidden();
        $this->actingAs($replacement)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Replacement Worker',
            ])
            ->assertRedirect();

        $completed = $run->fresh();
        $this->assertSame($replacement->id, $completed->completed_by_user_id);
        $this->assertSame(
            SiteChecklistRunExecutionService::AUTHORITY_RUN_ASSIGNEE,
            $completed->completion_authority,
        );
        $this->assertTrue($completed->hasVerifiableSignatureProvenance());
    }

    public function test_duplicate_completion_is_idempotent_and_cannot_replace_signature_or_responses(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner, createsHazard: true);

        $firstPayload = $this->payload($item, 'no', failed: true);
        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $firstPayload,
                'signature_name' => 'Original Signature',
            ])
            ->assertRedirect();

        $first = $run->fresh();
        $firstHash = $first->signature_payload_hash;
        $firstSignedAt = $first->signature_signed_at;

        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Replacement Signature',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Checklist already completed.');

        $replayed = $run->fresh();
        $this->assertSame('Original Signature', $replayed->signature_name);
        $this->assertSame($firstHash, $replayed->signature_payload_hash);
        $this->assertTrue($firstSignedAt->equalTo($replayed->signature_signed_at));
        $this->assertSame('no', $replayed->responses()->where('template_item_id', $item->id)->value('response_value'));
        $this->assertSame(1, $replayed->site->hazards()->where('linked_checklist_run_id', $run->id)->count());
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'checklist.completed')
            ->where('auditable_id', $run->id)
            ->count());
    }

    public function test_locked_completion_rechecks_a_reassignment_that_won_the_race(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $originalOwner = $this->siteUser('support_worker', $site);
        $replacement = $this->siteUser('support_worker', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $originalOwner);
        $staleRun = $run->fresh();

        SiteChecklistRun::query()->whereKey($run->id)->update([
            'assigned_to_user_id' => $replacement->id,
        ]);

        try {
            app(SiteChecklistRunExecutionService::class)->complete(
                $staleRun,
                $originalOwner,
                $this->payload($item, 'yes'),
                'Stale Owner',
                null,
                '203.0.113.10',
                'Race Test Agent',
            );
            $this->fail('A stale assignee completed a run after reassignment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame('in_progress', $run->fresh()->status);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower(str_replace(['`', '"'], '', $query->sql));
        });
        $this->actingAs($replacement)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Replacement Owner',
            ])
            ->assertRedirect();

        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from site_checklist_runs')
                && str_contains($sql, 'for update'),
        ), 'Checklist completion must lock the run before revalidating ownership.');
        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'from site_checklist_assignments')
                && str_contains($sql, 'for update'),
        ), 'Checklist completion must lock the assignment fallback before revalidating ownership.');
    }

    public function test_parallel_completion_and_reassignment_allow_only_the_lock_winner_to_mutate(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());

        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        $replacement = $this->siteUser('support_worker', $site);
        $manager = $this->siteUser('team_lead', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."checklist-race-release-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."checklist-race-ready-complete-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."checklist-race-ready-reassign-{$token}",
        ];
        $attemptPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."checklist-race-attempt-complete-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."checklist-race-attempt-reassign-{$token}",
        ];
        $processes = [];
        $userIds = [$owner->id, $replacement->id, $manager->id];

        $connection->commit();

        try {
            $connection->beginTransaction();
            SiteChecklistRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();

            $processes[] = $this->startRaceWorker(
                'complete',
                $database,
                $run->id,
                $owner->id,
                $replacement->id,
                $item->id,
                $readyPaths[0],
                $attemptPaths[0],
                $releasePath,
            );
            $processes[] = $this->startRaceWorker(
                'reassign',
                $database,
                $run->id,
                $manager->id,
                $replacement->id,
                $item->id,
                $readyPaths[1],
                $attemptPaths[1],
                $releasePath,
            );

            $this->waitForRaceFiles($processes, $readyPaths, 'Checklist race workers did not become ready.');
            file_put_contents($releasePath, 'go', LOCK_EX);
            $this->waitForRaceFiles($processes, $attemptPaths, 'Checklist race workers did not attempt their mutations.');
            usleep(250_000);
            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A checklist race worker exited before lock release.',
                );
            }

            $connection->commit();
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A checklist race worker failed.',
                );
                $statuses[] = json_decode(
                    trim($process->getOutput()),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                )['status'];
            }
            sort($statuses);

            $this->assertTrue(in_array($statuses, [
                ['completed', 'http_409'],
                ['http_403', 'reassigned'],
            ], true), 'The completion/reassignment race produced an unexpected outcome.');

            $final = $run->fresh();
            if ($statuses === ['completed', 'http_409']) {
                $this->assertSame('completed', $final->status);
                $this->assertSame($owner->id, $final->assigned_to_user_id);
                $this->assertSame(1, $final->responses()->count());
                $this->assertSame(1, AuditLog::query()->where('action', 'checklist.completed')->count());
                $this->assertSame(0, AuditLog::query()->where('action', 'checklist.reassigned')->count());
                $this->assertTrue($final->hasVerifiableSignatureProvenance());
            } else {
                $this->assertSame('in_progress', $final->status);
                $this->assertSame($replacement->id, $final->assigned_to_user_id);
                $this->assertSame(0, $final->responses()->count());
                $this->assertSame(0, AuditLog::query()->where('action', 'checklist.completed')->count());
                $this->assertSame(1, AuditLog::query()->where('action', 'checklist.reassigned')->count());
            }
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, ...$attemptPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            DB::table('audit_logs')->delete();
            DB::table('site_checklist_responses')->where('run_id', $run->id)->delete();
            DB::table('site_checklist_runs')->where('id', $run->id)->delete();
            DB::table('site_checklist_assignments')->where('id', $run->assignment_id)->delete();
            DB::table('site_checklist_template_items')->where('id', $item->id)->delete();
            DB::table('site_checklist_templates')->where('id', $run->template_id)->delete();
            DB::table('hr_employee_profiles')->whereIn('user_id', $userIds)->delete();
            DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
            DB::table('role_user')->whereIn('user_id', $userIds)->delete();
            DB::table('users')->whereIn('id', $userIds)->delete();
            DB::table('sites')->where('id', $site->id)->delete();

            $connection->beginTransaction();
        }
    }

    public function test_foreign_template_item_and_audit_failure_roll_back_all_completion_writes(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);
        [, $foreignItem] = $this->makeChecklistRun($site, runAssignee: $owner);

        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => [
                    ...$this->payload($item, 'yes'),
                    ...$this->payload($foreignItem, 'yes'),
                ],
                'signature_name' => 'Forged Item',
            ])
            ->assertSessionHasErrors('responses');

        $this->assertSame('in_progress', $run->fresh()->status);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);

        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated checklist audit failure.');
        });
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($owner)
                ->post("/checklists/runs/{$run->id}/complete", [
                    'responses' => $this->payload($item, 'yes'),
                    'signature_name' => 'Must Roll Back',
                ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
            Event::forget($eventName);
        }

        $this->assertSame('Simulated checklist audit failure.', $caught?->getMessage());
        $rolledBack = $run->fresh();
        $this->assertSame('in_progress', $rolledBack->status);
        $this->assertNull($rolledBack->completed_by_user_id);
        $this->assertNull($rolledBack->signature_name);
        $this->assertNull($rolledBack->signature_payload_hash);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);
    }

    public function test_completion_cannot_bypass_the_signature_provenance_contract(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        [$run] = $this->makeChecklistRun($site, runAssignee: $owner);

        $caught = null;
        try {
            $run->forceFill([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by_user_id' => $owner->id,
            ])->save();
        } catch (LogicException $exception) {
            $caught = $exception;
        }

        $this->assertSame(
            'Checklist completion requires verifiable signature provenance.',
            $caught?->getMessage(),
        );
        $this->assertSame('in_progress', $run->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'checklist.completed',
            'auditable_id' => $run->id,
        ]);
    }

    public function test_completed_run_rejects_response_edits_and_reassignment(): void
    {
        $site = Site::factory()->create(['type' => 'house']);
        $owner = $this->siteUser('support_worker', $site);
        $manager = $this->siteUser('team_lead', $site);
        $replacement = $this->siteUser('support_worker', $site);
        [$run, $item] = $this->makeChecklistRun($site, runAssignee: $owner);

        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $this->payload($item, 'yes'),
                'signature_name' => 'Immutable Signature',
            ])
            ->assertRedirect();
        $hash = $run->fresh()->signature_payload_hash;

        $this->actingAs($owner)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->payload($item, 'no'),
            ])
            ->assertStatus(409);
        $this->actingAs($manager)
            ->patch("/checklists/runs/{$run->id}/assign", [
                'assigned_to_user_id' => $replacement->id,
            ])
            ->assertStatus(409);
        $this->actingAs($manager)
            ->patch("/checklists/runs/{$run->id}/schedule", [
                'scheduled_date' => today()->addDay()->toDateString(),
            ])
            ->assertStatus(409);
        $this->actingAs($manager)
            ->post("/checklists/runs/{$run->id}/skip")
            ->assertRedirect()
            ->assertSessionHas('error', 'A completed run cannot be skipped.');

        $completed = $run->fresh();
        $this->assertSame($owner->id, $completed->assigned_to_user_id);
        $this->assertSame($hash, $completed->signature_payload_hash);
        $this->assertTrue($completed->hasVerifiableSignatureProvenance());
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'approved_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function startRaceWorker(
        string $action,
        string $database,
        int $runId,
        int $actorId,
        int $replacementId,
        int $itemId,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
    ): Process {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/ChecklistRunRaceWorker.php'),
            $action,
            $database,
            (string) $runId,
            (string) $actorId,
            (string) $replacementId,
            (string) $itemId,
            $readyPath,
            $attemptPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => $database,
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /**
     * @param  array<int, Process>  $processes
     * @param  array<int, string>  $paths
     */
    private function waitForRaceFiles(array $processes, array $paths, string $message): void
    {
        $deadline = microtime(true) + 20;
        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            foreach ($processes as $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    $this->fail(trim($process->getErrorOutput()) ?: $message);
                }
            }
            if (microtime(true) >= $deadline) {
                $this->fail($message);
            }

            usleep(20_000);
        }
    }

    private function siteUser(string $role, Site $site): User
    {
        $user = $this->roleUser($role);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user;
    }

    /** @return array{0: SiteChecklistRun, 1: SiteChecklistTemplateItem} */
    private function makeChecklistRun(
        Site $site,
        ?User $runAssignee = null,
        ?User $assignmentAssignee = null,
        bool $createsHazard = false,
    ): array {
        $template = SiteChecklistTemplate::create([
            'key' => 'ownership_'.Str::uuid(),
            'name' => 'Ownership Test',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $item = $template->items()->create([
            'question' => 'Is the check complete?',
            'response_type' => 'yes_no',
            'is_required' => true,
            'sort_order' => 0,
            'failure_creates_hazard' => $createsHazard,
        ]);
        $assignment = SiteChecklistAssignment::create([
            'site_id' => $site->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'assigned_to_user_id' => $assignmentAssignee?->id,
            'start_date' => today(),
            'is_active' => true,
        ]);
        $run = SiteChecklistRun::create([
            'assignment_id' => $assignment->id,
            'site_id' => $site->id,
            'template_id' => $template->id,
            'assigned_to_user_id' => $runAssignee?->id,
            'scheduled_date' => today(),
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return [$run, $item];
    }

    /** @return array<int, array<string, mixed>> */
    private function payload(
        SiteChecklistTemplateItem $item,
        string $value,
        bool $failed = false,
    ): array {
        return [[
            'template_item_id' => $item->id,
            'response_value' => $value,
            'is_failed' => $failed,
        ]];
    }
}
