<?php

namespace Tests\Feature\Safeguarding;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\SafeguardingTerminalTransition;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventClosureService;
use App\Services\Safeguarding\SafeguardingTerminalTransitionService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SafeguardingTerminalTransitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_foreign_site_direct_id_is_concealed_and_explicit_global_permission_is_positive(): void
    {
        $homeSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $actor = $this->actor($homeSite, ['safeguarding.update']);
        [$concern] = $this->readyJourney($foreignSite, $actor);

        $this->actingAs($actor)
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'The foreign-site direct object must remain concealed.',
            ])
            ->assertNotFound();

        $this->assertSame('monitoring', $concern->fresh()->status);
        $this->assertDatabaseCount('safeguarding_terminal_transitions', 0);
        $this->grant($actor, 'reports.viewAny');

        $this->actingAs($actor->fresh())
            ->post("/safeguarding/{$concern->id}/close", [
                'closure_summary' => 'The explicit global safeguarding review is complete.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('closed', $concern->fresh()->status);
        $transition = SafeguardingTerminalTransition::query()->sole();
        $this->assertSame('explicit_global', data_get($transition->authority_snapshot, 'site_scope'));
        $this->assertTrue($transition->authority_snapshot['permissions']['reports.viewAny']);
    }

    public function test_privacy_and_recorded_ownership_are_revalidated_under_locks(): void
    {
        $site = Site::factory()->create();
        $actor = $this->actor($site, ['safeguarding.update']);
        $owner = $this->actor($site, ['safeguarding.update']);
        [$sensitive] = $this->readyJourney($site, $owner, [
            'is_sensitive' => true,
            'reported_by_user_id' => $owner->id,
            'assigned_to_user_id' => $owner->id,
        ]);

        $this->actingAs($actor)
            ->post("/safeguarding/{$sensitive->id}/close", ['closure_summary' => 'Forbidden privacy attempt.'])
            ->assertNotFound();
        $this->assertSame('monitoring', $sensitive->fresh()->status);

        [$ownedByAnother] = $this->readyJourney($site, $owner, [
            'assigned_to_user_id' => $owner->id,
            'reported_by_user_id' => $actor->id,
        ]);
        $this->actingAs($actor)
            ->post("/safeguarding/{$ownedByAnother->id}/close", ['closure_summary' => 'Wrong owner attempt.'])
            ->assertForbidden();

        $this->grant($actor, SafeguardingTerminalTransitionService::CLOSE_ANY_PERMISSION);
        $this->actingAs($actor->fresh())
            ->post("/safeguarding/{$ownedByAnother->id}/close", [
                'closure_summary' => 'An explicitly authorised safeguarding lead reviewed the evidence.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame('closed', $ownedByAnother->fresh()->status);
    }

    public function test_override_text_alone_cannot_remove_live_visibility_but_distinct_authority_is_evidenced(): void
    {
        $site = Site::factory()->create();
        $actor = $this->actor($site, ['safeguarding.update']);
        [$concern] = $this->readyJourney($site, $actor);
        $action = SafeguardingActionPlan::query()->create([
            'safeguarding_concern_id' => $concern->id,
            'action_description' => 'Protective supervision remains open.',
            'action_type' => 'protective_measure',
            'status' => 'in_progress',
            'priority' => 1,
            'created_by' => $actor->id,
        ]);
        $payload = [
            'closure_summary' => 'Governed exceptional closure.',
            'override_reason' => 'The protective action remains visible in an approved care-plan control.',
        ];

        $this->actingAs($actor)
            ->post("/safeguarding/{$concern->id}/close", $payload)
            ->assertSessionHasErrors('override_reason');
        $this->assertSame('monitoring', $concern->fresh()->status);
        $this->assertDatabaseCount('safeguarding_terminal_transitions', 0);

        $this->grant($actor, SafeguardingTerminalTransitionService::OVERRIDE_PERMISSION);
        $this->actingAs($actor->fresh())
            ->post("/safeguarding/{$concern->id}/close", $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transition = SafeguardingTerminalTransition::query()->sole();
        $this->assertSame(SafeguardingTerminalTransitionService::OVERRIDE_PERMISSION, $transition->authority);
        $this->assertSame($action->id, data_get($transition->evidence_snapshot, 'concern.open_blockers.0.id'));
        $this->assertTrue(data_get($transition->evidence_snapshot, 'concern.override_applied'));
        $this->assertNotEmpty($transition->evidence_reference);
        $this->assertNotEmpty($transition->provenance_hash);
    }

    public function test_invalid_state_and_forged_cross_site_relation_fail_without_any_projection(): void
    {
        $site = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $actor = $this->actor($site, ['safeguarding.update']);
        [$reported] = $this->readyJourney($site, $actor, ['status' => 'reported']);

        $this->actingAs($actor)
            ->post("/safeguarding/{$reported->id}/close", ['closure_summary' => 'Illegal state transition.'])
            ->assertSessionHasErrors('close');
        $this->assertSame('reported', $reported->fresh()->status);

        [$forged, $event, $alert] = $this->readyJourney($site, $actor);
        $event->forceFill(['site_id' => $foreignSite->id])->save();
        $beforeContext = $alert->fresh()->context;

        $this->actingAs($actor)
            ->post("/safeguarding/{$forged->id}/close", ['closure_summary' => 'Forged relation attempt.'])
            ->assertSessionHasErrors('close');

        $this->assertSame('monitoring', $forged->fresh()->status);
        $this->assertSame($beforeContext, $alert->fresh()->context);
        $this->assertDatabaseCount('safeguarding_terminal_transitions', 0);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'safeguarding.concern.terminalTransitionApplied',
            'auditable_id' => $forged->id,
        ]);
    }

    public function test_partial_audit_failure_rolls_back_and_same_request_retries_then_replays_once(): void
    {
        $site = Site::factory()->create();
        $actor = $this->actor($site, ['safeguarding.update']);
        [$concern, $event, $alert] = $this->readyJourney($site, $actor);
        $service = app(SafeguardingTerminalTransitionService::class);
        $this->actingAs($actor);
        Event::listen('eloquent.creating: '.AuditLog::class, static function (): never {
            throw new RuntimeException('Injected terminal audit storage failure.');
        });

        try {
            $service->close($concern, $actor, 'Retryable terminal evidence.');
            $this->fail('The injected audit failure did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected terminal audit storage failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditLog::class);
        }

        $this->assertSame('monitoring', $concern->fresh()->status);
        $this->assertNull(data_get($alert->fresh()->context, 'journey_terminal'));
        $failed = SafeguardingTerminalTransition::query()->sole();
        $this->assertSame(SafeguardingTerminalTransition::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempt_count);
        $this->assertSame('RuntimeException', $failed->last_error_code);

        $applied = $service->close($concern->fresh(), $actor->fresh(), 'Retryable terminal evidence.');
        $replay = $service->close($concern->fresh(), $actor->fresh(), 'Retryable terminal evidence.');

        $this->assertTrue($applied->is($replay));
        $this->assertSame(SafeguardingTerminalTransition::STATUS_APPLIED, $applied->status);
        $this->assertSame(2, $applied->attempt_count);
        $this->assertSame('closed', $concern->fresh()->status);
        $this->assertSame($event->id, $applied->hs_event_id);
        $this->assertSame($alert->id, $applied->control_room_alert_id);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'safeguarding.concern.terminalTransitionApplied')
            ->where('auditable_id', $concern->id)
            ->count());
    }

    public function test_two_independent_mysql_workers_apply_one_terminal_transition(): void
    {
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $site = Site::factory()->create();
        $actor = $this->actor($site, ['safeguarding.update']);
        [$concern, $event, $alert] = $this->readyJourney($site, $actor);
        $database = $connection->getDatabaseName();
        $token = Str::uuid()->toString();
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-terminal-go-{$token}";
        $readyPaths = [
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-terminal-ready-a-{$token}",
            sys_get_temp_dir().DIRECTORY_SEPARATOR."safe-terminal-ready-b-{$token}",
        ];
        $processes = [];

        $connection->commit();

        try {
            foreach ($readyPaths as $readyPath) {
                $process = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/SafeguardingTerminalTransitionWorker.php'),
                    $database,
                    (string) $concern->id,
                    (string) $actor->id,
                    $readyPath,
                    $releasePath,
                ]);
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            foreach ($readyPaths as $index => $readyPath) {
                $this->waitForWorker($processes[$index], $readyPath);
            }
            file_put_contents($releasePath, 'go', LOCK_EX);

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), trim($process->getErrorOutput()) ?: 'Concurrent terminal worker failed.');
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }

            $this->assertCount(1, collect($results)->pluck('transition_id')->unique());
            $this->assertSame(['applied'], collect($results)->pluck('transition_status')->unique()->values()->all());
            $this->assertSame(['closed'], collect($results)->pluck('concern_status')->unique()->values()->all());
            $this->assertSame(1, DB::table('safeguarding_terminal_transitions')->where('safeguarding_concern_id', $concern->id)->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->where('action', 'safeguarding.concern.terminalTransitionApplied')
                ->where('auditable_id', $concern->id)
                ->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            try {
                DB::table('audit_logs')->where(function ($query) use ($concern, $event, $alert, $actor): void {
                    $query->where('user_id', $actor->id)
                        ->orWhereIn('auditable_id', [$concern->id, $event->id, $alert->id]);
                })->delete();
                DB::table('safeguarding_terminal_transitions')->where('safeguarding_concern_id', $concern->id)->delete();
                DB::table('hs_events')->where('id', $event->id)->delete();
                DB::table('control_room_alerts')->where('id', $alert->id)->delete();
                DB::table('safeguarding_concerns')->where('id', $concern->id)->delete();
                DB::table('hr_employee_profiles')->where('user_id', $actor->id)->delete();
                DB::table('permission_user')->where('user_id', $actor->id)->delete();
                DB::table('role_user')->where('user_id', $actor->id)->delete();
                DB::table('users')->where('id', $actor->id)->delete();
                DB::table('sites')->where('id', $site->id)->delete();
            } finally {
                $connection->beginTransaction();
            }
        }
    }

    /** @return array{SafeguardingConcern, HsEvent, ControlRoomAlert} */
    private function readyJourney(Site $site, User $actor, array $concernOverrides = []): array
    {
        $concern = SafeguardingConcern::factory()->create([
            'site_id' => $site->id,
            'status' => 'monitoring',
            'assigned_to_user_id' => $actor->id,
            'reported_by_user_id' => $actor->id,
            ...$concernOverrides,
        ]);
        $key = HsEvent::buildIdempotencyKey(SafeguardingConcern::class, $concern->id, HsEvent::CATEGORY_SAFEGUARDING);
        $event = HsEvent::query()->where('idempotency_key', $key)->first()
            ?? HsEvent::factory()->create([
                'source_type' => SafeguardingConcern::class,
                'source_id' => $concern->id,
                'event_category' => HsEvent::CATEGORY_SAFEGUARDING,
                'idempotency_key' => $key,
                'site_id' => $site->id,
            ]);
        $alert = $event->control_room_alert_id
            ? ControlRoomAlert::query()->findOrFail($event->control_room_alert_id)
            : ControlRoomAlert::factory()->create();
        $context = $alert->context ?? [];
        $context['concern_id'] = $concern->id;
        $alert->forceFill([
            'site_id' => $site->id,
            'source' => 'safeguarding',
            'status' => ControlRoomAlert::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by_user_id' => $actor->id,
            'resolution_code' => 'safeguarding_response_complete',
            'context' => $context,
        ])->save();
        $actor = $this->grantHsClosureAuthority($actor, $site->id);
        $event->forceFill([
            'site_id' => $site->id,
            'control_room_alert_id' => $alert->id,
            'status' => HsEvent::STATUS_OPEN,
            'owner_user_id' => $actor->id,
            'handover_status' => HsEvent::HANDOVER_NOT_REQUIRED,
            'investigation_required' => false,
            'worksafe_notifiable' => false,
            'worksafe_decided_at' => now(),
            'worksafe_decided_by_user_id' => $actor->id,
            'worksafe_decision_reason' => 'Assessed as not meeting the WorkSafe notification threshold.',
            'worksafe_decision_source' => 'manual',
            'worksafe_status' => null,
        ])->save();
        $this->actingAs($actor);
        $event = app(HsEventClosureService::class)->closeEvent(
            $event->fresh(),
            'Canonical H&S safeguarding work completed.',
            $actor,
        );

        return [$concern, $event, $alert];
    }

    private function grantHsClosureAuthority(User $actor, ?int $siteId): User
    {
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();
        $actor->roles()->syncWithoutDetaching([$role->id]);
        if (! HrEmployeeProfile::query()->where('user_id', $actor->id)->exists()) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $siteId,
                'secondary_site_ids' => [],
                'position_role' => 'health_safety_officer',
            ]);
        }

        return $actor->fresh();
    }

    private function actor(Site $site, array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
        ]);
        foreach ($permissions as $permission) {
            $this->grant($user, $permission);
        }

        return $user;
    }

    private function grant(User $user, string $permissionKey): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['description' => str_replace('.', ' ', $permissionKey), 'group' => explode('.', $permissionKey)[0]],
        );
        $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $user->unsetRelations();
    }

    private function waitForWorker(Process $process, string $readyPath): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($readyPath)) {
            if (! $process->isRunning()) {
                $this->fail(trim($process->getErrorOutput()) ?: 'Terminal worker exited before becoming ready.');
            }
            if (microtime(true) >= $deadline) {
                $this->fail('Terminal worker did not reach the concurrency barrier.');
            }
            usleep(20_000);
        }
    }
}
