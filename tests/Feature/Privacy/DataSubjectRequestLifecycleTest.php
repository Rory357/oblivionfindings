<?php

namespace Tests\Feature\Privacy;

use App\Domain\Privacy\Services\DataSubjectRequestLifecycleService;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\DataSubjectRequest;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Tasks\Providers\DataSubjectRequestProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DataSubjectRequestLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_put_rejects_lifecycle_fields_and_keeps_the_record_unchanged(): void
    {
        $actor = $this->processor();
        $request = $this->request();

        $this->actingAs($actor)
            ->put("/privacy/requests/{$request->id}", [
                'status' => 'completed',
                'completion_notes' => 'Bypass the lifecycle command.',
                'completed_at' => now()->toIso8601String(),
                'completed_by_user_id' => $actor->id,
            ])
            ->assertSessionHasErrors([
                'status',
                'completion_notes',
                'completed_at',
                'completed_by_user_id',
            ]);

        $request->refresh();

        $this->assertSame('identity_verification', $request->status);
        $this->assertNull($request->completed_at);
        $this->assertNull($request->completed_by_user_id);
        $this->assertNull($request->completion_notes);
    }

    public function test_valid_assignment_verify_extend_complete_sequence_is_audited_and_retains_assignment(): void
    {
        $actor = $this->processor();
        $assignee = User::factory()->create();
        $this->grant($assignee, 'privacy.processRequests');
        $request = $this->request();
        $service = app(DataSubjectRequestLifecycleService::class);

        $service->assign($request, $actor, $assignee->id);
        $assignedAt = $request->fresh()->assigned_at?->copy();
        $service->verifyIdentity($request, $actor, 'Photo identification checked in person.');
        $service->extend($request, $actor, 'Records are held across several services.', now()->addDays(30)->toDateString());
        $service->complete($request, $actor, 'The response was supplied securely.');

        $request->refresh();

        $this->assertSame('completed', $request->status);
        $this->assertSame('verified', $request->identity_verified);
        $this->assertSame($actor->id, $request->verified_by_user_id);
        $this->assertSame($actor->id, $request->completed_by_user_id);
        $this->assertSame($assignee->id, $request->assigned_to_user_id);
        $this->assertTrue($request->assigned_at?->equalTo($assignedAt) ?? false);
        $this->assertNotNull($request->completed_at);

        $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
        $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
        $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_EXTENDED));
        $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_COMPLETED));
        $this->assertSame([$actor->id], AuditLog::query()
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->whereIn('action', [
                DataSubjectRequestLifecycleService::AUDIT_ASSIGNED,
                DataSubjectRequestLifecycleService::AUDIT_VERIFIED,
                DataSubjectRequestLifecycleService::AUDIT_EXTENDED,
                DataSubjectRequestLifecycleService::AUDIT_COMPLETED,
            ])
            ->pluck('user_id')
            ->map(fn ($userId): int => (int) $userId)
            ->unique()
            ->values()
            ->all());
    }

    public function test_assignment_rejects_unapproved_and_non_processor_assignees_through_every_entry_point(): void
    {
        $actor = $this->processor();
        $unapprovedProcessor = User::factory()->create(['approved_at' => null]);
        $this->grant($unapprovedProcessor, 'privacy.processRequests');
        $approvedNonProcessor = User::factory()->create();

        foreach ([
            'unapproved' => $unapprovedProcessor,
            'non-processor' => $approvedNonProcessor,
        ] as $case => $ineligibleAssignee) {
            $email = "assignment-create-{$case}@example.test";
            $auditCount = AuditLog::query()->count();

            $this->actingAs($actor)
                ->post('/privacy/requests', [
                    'request_type' => 'access',
                    'subject_name' => 'Rejected assignment intake',
                    'subject_email' => $email,
                    'request_details' => 'The intake transaction must roll back.',
                    'assigned_to_user_id' => $ineligibleAssignee->id,
                ])
                ->assertConflict();

            $this->assertDatabaseMissing('data_subject_requests', ['subject_email' => $email]);
            $this->assertSame($auditCount, AuditLog::query()->count());

            foreach (['put', 'task-provider', 'direct-service'] as $entryPoint) {
                $request = $this->request();
                $request->refresh();
                $before = $request->getRawOriginal();
                $auditCount = AuditLog::query()
                    ->where('auditable_type', $request->getMorphClass())
                    ->where('auditable_id', $request->id)
                    ->count();

                if ($entryPoint === 'put') {
                    $this->actingAs($actor)
                        ->put("/privacy/requests/{$request->id}", [
                            'assigned_to_user_id' => $ineligibleAssignee->id,
                        ])
                        ->assertConflict();
                } elseif ($entryPoint === 'task-provider') {
                    $this->actingAs($actor);
                    $this->assertConflict(fn () => app(DataSubjectRequestProvider::class)
                        ->assign($actor, $request->id, $ineligibleAssignee->id));
                } else {
                    $this->actingAs($actor);
                    $this->assertConflict(fn () => app(DataSubjectRequestLifecycleService::class)
                        ->assign($request, $actor, $ineligibleAssignee->id));
                }

                $fresh = $request->fresh();
                $this->assertSame($before, $fresh->getRawOriginal());
                $this->assertSame($auditCount, AuditLog::query()
                    ->where('auditable_type', $request->getMorphClass())
                    ->where('auditable_id', $request->id)
                    ->count());
            }
        }
    }

    public function test_assignment_accepts_approved_explicit_processors_and_preserves_first_assigned_at(): void
    {
        $actor = $this->processor();
        $firstAssignee = User::factory()->create();
        $secondAssignee = User::factory()->create();
        $this->grant($firstAssignee, 'privacy.processRequests');
        $this->grant($secondAssignee, 'privacy.processRequests');
        $request = $this->request();
        $service = app(DataSubjectRequestLifecycleService::class);

        $service->assign($request, $actor, $firstAssignee->id);
        $assignedAt = $request->fresh()->assigned_at?->copy();
        $service->assign($request, $actor, $secondAssignee->id);

        $request->refresh();
        $this->assertSame($secondAssignee->id, $request->assigned_to_user_id);
        $this->assertTrue($request->assigned_at?->equalTo($assignedAt) ?? false);
        $this->assertSame(2, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
    }

    public function test_put_can_unassign_without_rewriting_assigned_at_or_duplicating_replay_audit(): void
    {
        $actor = $this->processor();
        $assignee = User::factory()->create();
        $this->grant($assignee, 'privacy.processRequests');
        $request = $this->request();

        app(DataSubjectRequestLifecycleService::class)->assign($request, $actor, $assignee->id);
        $assignedAt = $request->fresh()->assigned_at?->copy();

        $this->actingAs($actor)
            ->put("/privacy/requests/{$request->id}", ['assigned_to_user_id' => null])
            ->assertRedirect();

        $request->refresh();
        $this->assertNull($request->assigned_to_user_id);
        $this->assertTrue($request->assigned_at?->equalTo($assignedAt) ?? false);

        $assignmentAudits = AuditLog::query()
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->where('action', DataSubjectRequestLifecycleService::AUDIT_ASSIGNED)
            ->get();
        $unassignmentAudits = $assignmentAudits->filter(fn (AuditLog $audit): bool => array_key_exists('to_assignee_id', $audit->meta)
            && $audit->meta['to_assignee_id'] === null);

        $this->assertCount(2, $assignmentAudits);
        $this->assertCount(1, $unassignmentAudits);
        $this->assertSame($assignee->id, $unassignmentAudits->first()->meta['from_assignee_id']);

        $this->actingAs($actor)
            ->put("/privacy/requests/{$request->id}", ['assigned_to_user_id' => null])
            ->assertRedirect();

        $request->refresh();
        $this->assertNull($request->assigned_to_user_id);
        $this->assertTrue($request->assigned_at?->equalTo($assignedAt) ?? false);
        $replayedAssignmentAudits = AuditLog::query()
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->where('action', DataSubjectRequestLifecycleService::AUDIT_ASSIGNED)
            ->get();
        $this->assertCount(2, $replayedAssignmentAudits);
        $this->assertCount(1, $replayedAssignmentAudits->filter(fn (AuditLog $audit): bool => array_key_exists('to_assignee_id', $audit->meta)
            && $audit->meta['to_assignee_id'] === null));
    }

    public function test_direct_intake_discards_forged_lifecycle_and_actor_owned_attributes(): void
    {
        $actor = $this->processor();
        $forgedUser = User::factory()->create();

        $request = app(DataSubjectRequestLifecycleService::class)->intake(
            $actor,
            [
                'request_type' => 'access',
                'subject_name' => 'Forged lifecycle intake',
                'subject_email' => 'forged-lifecycle-intake@example.test',
                'request_details' => 'Only allow-listed intake fields may survive.',
                'status' => 'completed',
                'identity_verified' => 'verified',
                'identity_verified_at' => now()->subDay(),
                'verified_by_user_id' => $forgedUser->id,
                'verification_method' => 'Forged verification provenance.',
                'completed_at' => now()->subDay(),
                'completed_by_user_id' => $forgedUser->id,
                'completion_notes' => 'Forged completion provenance.',
                'refused_at' => now()->subDay(),
                'refused_by_user_id' => $forgedUser->id,
                'rejection_reason' => 'Forged refusal reason.',
                'rejection_legal_basis' => 'Forged refusal legal basis.',
                'created_by' => $forgedUser->id,
                'updated_by' => $forgedUser->id,
                'assigned_to_user_id' => $forgedUser->id,
                'assigned_at' => now()->subDay(),
            ],
            'direct.test',
        );

        $this->assertSame($actor->id, $request->created_by);
        $this->assertNull($request->updated_by);
        $this->assertSame('identity_verification', $request->status);
        $this->assertSame('pending', $request->identity_verified);
        $this->assertNull($request->identity_verified_at);
        $this->assertNull($request->verified_by_user_id);
        $this->assertNull($request->verification_method);
        $this->assertNull($request->completed_at);
        $this->assertNull($request->completed_by_user_id);
        $this->assertNull($request->completion_notes);
        $this->assertNull($request->refused_at);
        $this->assertNull($request->refused_by_user_id);
        $this->assertNull($request->rejection_reason);
        $this->assertNull($request->rejection_legal_basis);
        $this->assertNull($request->assigned_to_user_id);
        $this->assertNull($request->assigned_at);
        $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(0, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
        $this->assertSame(0, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
    }

    public function test_eligible_processors_can_be_assigned_by_canonical_post_and_task_provider(): void
    {
        $actor = $this->processor();
        $canonicalAssignee = User::factory()->create();
        $taskAssignee = User::factory()->create();
        $this->grant($canonicalAssignee, 'privacy.processRequests');
        $this->grant($taskAssignee, 'privacy.processRequests');

        $this->actingAs($actor)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Canonical assignment positive',
                'subject_email' => 'canonical-assignment-positive@example.test',
                'request_details' => 'Assign through canonical intake.',
                'assigned_to_user_id' => $canonicalAssignee->id,
            ])
            ->assertRedirect();

        $canonicalRequest = DataSubjectRequest::query()
            ->where('subject_email', 'canonical-assignment-positive@example.test')
            ->firstOrFail();
        $this->assertSame($canonicalAssignee->id, $canonicalRequest->assigned_to_user_id);
        $this->assertNotNull($canonicalRequest->assigned_at);
        $this->assertSame(1, $this->auditCount($canonicalRequest, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(1, $this->auditCount($canonicalRequest, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));

        $taskRequest = $this->request();
        $this->actingAs($actor);
        app(DataSubjectRequestProvider::class)->assign($actor, $taskRequest->id, $taskAssignee->id);

        $taskRequest->refresh();
        $this->assertSame($taskAssignee->id, $taskRequest->assigned_to_user_id);
        $this->assertNotNull($taskRequest->assigned_at);
        $this->assertSame(1, $this->auditCount($taskRequest, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
    }

    public function test_complete_and_refuse_require_verified_in_progress_state(): void
    {
        $actor = $this->processor();
        $service = app(DataSubjectRequestLifecycleService::class);

        $unverifiedInProgress = $this->request(['status' => 'in_progress']);
        $completeConflict = false;
        try {
            $service->complete($unverifiedInProgress, $actor, 'Unsafe completion.');
        } catch (ConflictHttpException) {
            $completeConflict = true;
        }

        $unverifiedInProgress->refresh();
        $this->assertTrue($completeConflict);
        $this->assertSame('in_progress', $unverifiedInProgress->status);
        $this->assertNull($unverifiedInProgress->completed_at);

        $unverifiedRefusal = $this->request(['status' => 'in_progress']);
        $refusalConflict = false;
        try {
            $service->refuse($unverifiedRefusal, $actor, 'Not established.', 'Privacy Act 2020 section 53.');
        } catch (ConflictHttpException) {
            $refusalConflict = true;
        }

        $unverifiedRefusal->refresh();
        $this->assertTrue($refusalConflict);
        $this->assertSame('in_progress', $unverifiedRefusal->status);
        $this->assertNull($unverifiedRefusal->refused_at);
        $this->assertNull($unverifiedRefusal->refused_by_user_id);
    }

    public function test_direct_service_rejects_invalid_extension_and_blank_verification_or_refusal_provenance(): void
    {
        $actor = $this->processor();
        $service = app(DataSubjectRequestLifecycleService::class);
        $unverified = $this->request();

        foreach ([
            fn () => $service->verifyIdentity($unverified, $actor, '   '),
            fn () => $service->extend($unverified, $actor, '   ', now()->addDays(5)->toDateString()),
            fn () => $service->extend($unverified, $actor, 'Valid reason.', 'not-a-date'),
            fn () => $service->extend($unverified, $actor, 'Valid reason.', now()->subDay()->toDateString()),
        ] as $command) {
            $this->assertConflict($command);
        }

        $unverified->refresh();
        $this->assertSame('pending', $unverified->identity_verified);
        $this->assertNull($unverified->identity_verified_at);
        $this->assertFalse((bool) $unverified->extension_requested);
        $this->assertNull($unverified->extended_due_date);
        $this->assertSame(0, $this->auditCount($unverified, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
        $this->assertSame(0, $this->auditCount($unverified, DataSubjectRequestLifecycleService::AUDIT_EXTENDED));

        $verified = $this->verifiedRequest($actor);
        $this->assertConflict(fn () => $service->refuse(
            $verified,
            $actor,
            '   ',
            'Privacy Act 2020 section 53.',
        ));
        $this->assertConflict(fn () => $service->refuse(
            $verified,
            $actor,
            'Substantive refusal reason.',
            '   ',
        ));

        $verified->refresh();
        $this->assertSame('in_progress', $verified->status);
        $this->assertNull($verified->refused_at);
        $this->assertNull($verified->refused_by_user_id);
        $this->assertSame(0, $this->auditCount($verified, DataSubjectRequestLifecycleService::AUDIT_REFUSED));
    }

    public function test_terminal_replays_are_idempotent_but_reopen_and_cross_terminal_commands_conflict(): void
    {
        $actor = $this->processor();
        $otherAssignee = User::factory()->create();
        $service = app(DataSubjectRequestLifecycleService::class);
        $completed = $this->verifiedRequest($actor);

        $service->complete($completed, $actor, 'Original completion.');
        $completed->refresh();
        $completedAt = $completed->completed_at?->copy();
        $updatedAt = $completed->updated_at->copy();

        $service->complete($completed, $actor, 'Original completion.');

        $completed->refresh();
        $this->assertTrue($completed->completed_at?->equalTo($completedAt) ?? false);
        $this->assertTrue($completed->updated_at->equalTo($updatedAt));
        $this->assertSame(1, $this->auditCount($completed, DataSubjectRequestLifecycleService::AUDIT_COMPLETED));

        foreach ([
            fn () => $service->complete($completed, $actor, 'Changed replay.'),
            fn () => $service->refuse($completed, $actor, 'Conflicting outcome.', 'Privacy Act 2020 section 53.'),
            fn () => $service->verifyIdentity($completed, $actor, 'Changed verification.'),
            fn () => $service->extend($completed, $actor, 'Late extension.', now()->addDays(60)->toDateString()),
            fn () => $service->assign($completed, $actor, $otherAssignee->id),
        ] as $command) {
            $this->assertConflict($command);
        }

        $refused = $this->verifiedRequest($actor);
        $service->refuse($refused, $actor, 'The request is vexatious.', 'Privacy Act 2020 section 53.');
        $refused->refresh();
        $refusedAt = $refused->refused_at?->copy();
        $updatedAt = $refused->updated_at->copy();

        $service->refuse($refused, $actor, 'The request is vexatious.', 'Privacy Act 2020 section 53.');

        $refused->refresh();
        $this->assertTrue($refused->refused_at?->equalTo($refusedAt) ?? false);
        $this->assertTrue($refused->updated_at->equalTo($updatedAt));
        $this->assertSame($actor->id, $refused->refused_by_user_id);
        $this->assertSame(1, $this->auditCount($refused, DataSubjectRequestLifecycleService::AUDIT_REFUSED));
        $this->assertConflict(fn () => $service->complete($refused, $actor, 'Cross-terminal overwrite.'));

        $withdrawn = $this->verifiedRequest($actor, ['status' => 'withdrawn']);
        $this->assertConflict(fn () => $service->complete($withdrawn, $actor, 'Reopen withdrawn request.'));
        $this->assertConflict(fn () => $service->refuse($withdrawn, $actor, 'Reopen.', 'No basis.'));
    }

    public function test_audit_failure_rolls_back_the_owning_lifecycle_transaction(): void
    {
        $actor = $this->processor();
        $assignee = User::factory()->create();
        $request = $this->verifiedRequest($actor, [
            'assigned_to_user_id' => $assignee->id,
            'assigned_at' => now()->subDay(),
        ]);

        $failAudit = true;
        AuditLog::creating(function (AuditLog $audit) use (&$failAudit): void {
            if ($failAudit && $audit->action === DataSubjectRequestLifecycleService::AUDIT_COMPLETED) {
                throw new RuntimeException('forced DSR audit failure');
            }
        });

        try {
            try {
                app(DataSubjectRequestLifecycleService::class)
                    ->complete($request, $actor, 'This must roll back.');
                $this->fail('The forced audit failure was not raised.');
            } catch (RuntimeException $exception) {
                $this->assertSame('forced DSR audit failure', $exception->getMessage());
            }
        } finally {
            $failAudit = false;
        }

        $request->refresh();
        $this->assertSame('in_progress', $request->status);
        $this->assertNull($request->completed_at);
        $this->assertNull($request->completed_by_user_id);
        $this->assertNull($request->completion_notes);
        $this->assertSame($assignee->id, $request->assigned_to_user_id);
        $this->assertSame(0, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_COMPLETED));
    }

    public function test_role_string_and_direct_object_calls_are_denied_but_explicit_global_permission_succeeds(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $request = $this->request(['client_id' => $client->id]);
        $roleOnlyAdmin = User::factory()->create(['role' => 'admin']);
        $globalProcessor = $this->processor(['role' => 'support_worker']);

        $this->actingAs($roleOnlyAdmin)
            ->post("/privacy/requests/{$request->id}/verify-identity", [
                'verification_method' => 'Unauthorised direct-object attempt.',
            ])
            ->assertForbidden();

        $serviceDenied = false;
        try {
            app(DataSubjectRequestLifecycleService::class)
                ->verifyIdentity($request, $roleOnlyAdmin, 'Direct service attempt.');
        } catch (AuthorizationException) {
            $serviceDenied = true;
        }

        $request->refresh();
        $this->assertTrue($serviceDenied);
        $this->assertSame('identity_verification', $request->status);
        $this->assertSame('pending', $request->identity_verified);

        $this->assertDenied(fn () => app(DataSubjectRequestLifecycleService::class)
            ->verifyIdentity($request, $globalProcessor, 'Nominated actor substitution.'));

        $this->actingAs($globalProcessor);
        app(DataSubjectRequestLifecycleService::class)
            ->verifyIdentity($request, $globalProcessor, 'Explicit global privacy authority.');

        $request->refresh();
        $this->assertSame('in_progress', $request->status);
        $this->assertSame($globalProcessor->id, $request->verified_by_user_id);
    }

    public function test_canonical_intake_audits_unverified_and_verified_branches(): void
    {
        $actor = $this->processor();

        $this->actingAs($actor)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Canonical unverified intake',
                'subject_email' => 'canonical-unverified-intake@example.test',
                'request_details' => 'Identity evidence is still outstanding.',
            ])
            ->assertRedirect();

        $unverified = DataSubjectRequest::query()
            ->where('subject_email', 'canonical-unverified-intake@example.test')
            ->firstOrFail();

        $this->assertSame('identity_verification', $unverified->status);
        $this->assertSame('pending', $unverified->identity_verified);
        $this->assertSame(1, $this->auditCount($unverified, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(0, $this->auditCount($unverified, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));

        $this->actingAs($actor)
            ->post('/privacy/requests', [
                'request_type' => 'access',
                'subject_name' => 'Canonical verified intake',
                'subject_email' => 'canonical-verified-intake@example.test',
                'request_details' => 'Identity evidence was checked at intake.',
                'verification_method' => 'Government photo identification checked in person.',
            ])
            ->assertRedirect();

        $verified = DataSubjectRequest::query()
            ->where('subject_email', 'canonical-verified-intake@example.test')
            ->firstOrFail();

        $this->assertSame('in_progress', $verified->status);
        $this->assertSame('verified', $verified->identity_verified);
        $this->assertSame($actor->id, $verified->verified_by_user_id);
        $this->assertSame(1, $this->auditCount($verified, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(1, $this->auditCount($verified, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
    }

    public function test_settings_intake_requires_privacy_processing_authority_for_verified_and_unverified_requests(): void
    {
        $settingsOnly = User::factory()->create(['role' => 'admin']);
        $this->grant($settingsOnly, 'settings.access.manage');

        $payload = [
            'request_type' => 'access',
            'requester_name' => 'Settings intake subject',
            'relationship' => 'self',
            'details' => 'A request entered through the existing settings surface.',
        ];

        foreach ([false, true] as $identityVerified) {
            $email = $identityVerified
                ? 'settings-verified-denied@example.test'
                : 'settings-unverified-denied@example.test';

            $this->actingAs($settingsOnly)
                ->postJson('/settings/data/requests', [
                    ...$payload,
                    'requester_email' => $email,
                    'identity_verified' => $identityVerified,
                ])
                ->assertForbidden();

            $this->assertDatabaseMissing('data_subject_requests', [
                'subject_email' => $email,
            ]);
        }

        $globalProcessor = $this->processor(['role' => 'support_worker']);
        $this->grant($globalProcessor, 'settings.access.manage');

        $this->actingAs($globalProcessor)
            ->postJson('/settings/data/requests', [
                ...$payload,
                'requester_email' => 'settings-unverified-intake@example.test',
                'identity_verified' => false,
            ])
            ->assertOk();

        $unverifiedRequest = DataSubjectRequest::query()
            ->where('subject_email', 'settings-unverified-intake@example.test')
            ->firstOrFail();

        $this->assertSame('identity_verification', $unverifiedRequest->status);
        $this->assertSame('pending', $unverifiedRequest->identity_verified);
        $this->assertNull($unverifiedRequest->verified_by_user_id);
        $this->assertSame(1, $this->auditCount($unverifiedRequest, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(0, $this->auditCount($unverifiedRequest, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));

        $this->actingAs($globalProcessor)
            ->postJson('/settings/data/requests', [
                ...$payload,
                'requester_email' => 'settings-verified-intake@example.test',
                'identity_verified' => true,
            ])
            ->assertOk();

        $verifiedRequest = DataSubjectRequest::query()
            ->where('subject_email', 'settings-verified-intake@example.test')
            ->firstOrFail();

        $this->assertSame('in_progress', $verifiedRequest->status);
        $this->assertSame('verified', $verifiedRequest->identity_verified);
        $this->assertSame($globalProcessor->id, $verifiedRequest->verified_by_user_id);
        $this->assertSame(1, $this->auditCount($verifiedRequest, DataSubjectRequestLifecycleService::AUDIT_CREATED));
        $this->assertSame(1, $this->auditCount($verifiedRequest, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
    }

    public function test_forced_intake_audit_failure_rolls_back_canonical_and_both_settings_branches(): void
    {
        $actor = $this->processor();
        $this->grant($actor, 'settings.access.manage');
        $failAudit = true;

        AuditLog::creating(function (AuditLog $audit) use (&$failAudit): void {
            if ($failAudit && $audit->action === DataSubjectRequestLifecycleService::AUDIT_CREATED) {
                throw new RuntimeException('forced DSR intake audit failure');
            }
        });

        $this->withoutExceptionHandling();

        $cases = [
            'canonical' => [
                'email' => 'canonical-intake-audit-failure@example.test',
                'submit' => fn () => $this->actingAs($actor)->post('/privacy/requests', [
                    'request_type' => 'access',
                    'subject_name' => 'Canonical audit rollback',
                    'subject_email' => 'canonical-intake-audit-failure@example.test',
                    'request_details' => 'This intake must not persist.',
                ]),
            ],
            'settings-unverified' => [
                'email' => 'settings-unverified-audit-failure@example.test',
                'submit' => fn () => $this->actingAs($actor)->postJson('/settings/data/requests', [
                    'request_type' => 'access',
                    'requester_name' => 'Settings unverified rollback',
                    'requester_email' => 'settings-unverified-audit-failure@example.test',
                    'relationship' => 'self',
                    'identity_verified' => false,
                ]),
            ],
            'settings-verified' => [
                'email' => 'settings-verified-audit-failure@example.test',
                'submit' => fn () => $this->actingAs($actor)->postJson('/settings/data/requests', [
                    'request_type' => 'access',
                    'requester_name' => 'Settings verified rollback',
                    'requester_email' => 'settings-verified-audit-failure@example.test',
                    'relationship' => 'self',
                    'identity_verified' => true,
                ]),
            ],
        ];

        try {
            foreach ($cases as $case => $payload) {
                $auditCount = AuditLog::query()->count();

                try {
                    $payload['submit']();
                    $this->fail("{$case} intake did not surface the forced audit failure.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('forced DSR intake audit failure', $exception->getMessage());
                }

                $this->assertDatabaseMissing('data_subject_requests', [
                    'subject_email' => $payload['email'],
                ]);
                $this->assertSame($auditCount, AuditLog::query()->count());
            }
        } finally {
            $failAudit = false;
        }
    }

    public function test_verified_intake_audit_failure_rolls_back_canonical_and_settings_created_provenance(): void
    {
        $actor = $this->processor();
        $this->grant($actor, 'settings.access.manage');
        $failAudit = true;

        AuditLog::creating(function (AuditLog $audit) use (&$failAudit): void {
            if ($failAudit && $audit->action === DataSubjectRequestLifecycleService::AUDIT_VERIFIED) {
                throw new RuntimeException('forced verified intake audit failure');
            }
        });

        $this->withoutExceptionHandling();

        $cases = [
            'canonical-verified' => [
                'email' => 'canonical-verified-audit-failure@example.test',
                'submit' => fn () => $this->actingAs($actor)->post('/privacy/requests', [
                    'request_type' => 'access',
                    'subject_name' => 'Canonical verified audit rollback',
                    'subject_email' => 'canonical-verified-audit-failure@example.test',
                    'request_details' => 'Both strict audits must roll back.',
                    'verification_method' => 'Government photo identification checked.',
                ]),
            ],
            'settings-verified' => [
                'email' => 'settings-verified-event-audit-failure@example.test',
                'submit' => fn () => $this->actingAs($actor)->postJson('/settings/data/requests', [
                    'request_type' => 'access',
                    'requester_name' => 'Settings verified audit rollback',
                    'requester_email' => 'settings-verified-event-audit-failure@example.test',
                    'relationship' => 'self',
                    'identity_verified' => true,
                ]),
            ],
        ];

        try {
            foreach ($cases as $case => $payload) {
                $auditCount = AuditLog::query()->count();

                try {
                    $payload['submit']();
                    $this->fail("{$case} intake did not surface the forced verification audit failure.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('forced verified intake audit failure', $exception->getMessage());
                }

                $this->assertDatabaseMissing('data_subject_requests', [
                    'subject_email' => $payload['email'],
                ]);
                $this->assertSame($auditCount, AuditLog::query()->count());
                $this->assertDatabaseMissing('audit_logs', [
                    'action' => DataSubjectRequestLifecycleService::AUDIT_CREATED,
                ]);
            }
        } finally {
            $failAudit = false;
        }
    }

    public function test_settings_index_withholds_dsr_rows_and_requester_pii_without_view_authority(): void
    {
        $this->request([
            'subject_name' => 'Protected Settings Requester',
            'subject_email' => 'protected-settings-requester@example.test',
        ]);

        $settingsOnly = User::factory()->create(['role' => 'admin']);
        $this->grant($settingsOnly, 'settings.access.manage');

        $this->actingAs($settingsOnly)
            ->get('/settings/data')
            ->assertOk()
            ->assertDontSee('Protected Settings Requester')
            ->assertDontSee('protected-settings-requester@example.test')
            ->assertInertia(fn ($page) => $page
                ->component('settings/data')
                ->where('privacy_capabilities.view_requests', false)
                ->where('privacy_capabilities.process_requests', false)
                ->has('dsar_requests', 0));

        $globalViewer = User::factory()->create(['role' => 'support_worker']);
        $this->grant($globalViewer, 'settings.access.manage');
        $this->grant($globalViewer, 'privacy.viewRequests');

        $this->actingAs($globalViewer)
            ->get('/settings/data')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/data')
                ->where('privacy_capabilities.view_requests', true)
                ->where('privacy_capabilities.process_requests', false)
                ->has('dsar_requests', 1)
                ->where('dsar_requests.0.requester', 'Protected Settings Requester'));
    }

    public function test_two_process_complete_versus_refuse_serializes_one_terminal_outcome(): void
    {
        $actor = $this->processor();
        $assignee = User::factory()->create();
        $request = $this->verifiedRequest($actor, [
            'assigned_to_user_id' => $assignee->id,
            'assigned_at' => now()->subHour(),
        ]);

        try {
            $results = $this->runConcurrentCommands($request, $actor, [
                ['action' => 'complete', 'notes' => 'Concurrent completion.'],
                ['action' => 'refuse', 'reason' => 'Concurrent refusal.', 'legal_basis' => 'Privacy Act 2020 section 53.'],
            ]);

            $request->refresh();

            $this->assertSame(['conflict', 'success'], collect($results)->pluck('outcome')->sort()->values()->all());
            $this->assertContains($request->status, ['completed', 'rejected']);
            $this->assertSame($assignee->id, $request->assigned_to_user_id);
            $this->assertSame(1, $this->terminalAuditCount($request));

            if ($request->status === 'completed') {
                $this->assertNotNull($request->completed_at);
                $this->assertNull($request->refused_at);
            } else {
                $this->assertNotNull($request->refused_at);
                $this->assertNull($request->completed_at);
            }
        } finally {
            $this->cleanupConcurrentState($request, $actor, [$assignee->id]);
        }
    }

    public function test_two_process_verify_versus_complete_revalidates_prerequisites_under_lock(): void
    {
        $actor = $this->processor();
        $request = $this->request();

        try {
            $results = $this->runConcurrentCommands($request, $actor, [
                ['action' => 'verify', 'method' => 'Concurrent identity verification.'],
                ['action' => 'complete', 'notes' => 'Concurrent completion after verification.'],
            ]);

            $request->refresh();

            $this->assertSame('success', collect($results)->firstWhere('action', 'verify')['outcome']);
            $this->assertContains($request->status, ['in_progress', 'completed']);
            $this->assertSame('verified', $request->identity_verified);
            $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_VERIFIED));
            $this->assertContains(
                $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_COMPLETED),
                [0, 1],
            );

            if ($request->status === 'completed') {
                $this->assertSame(['success', 'success'], collect($results)->pluck('outcome')->sort()->values()->all());
            } else {
                $this->assertSame(['conflict', 'success'], collect($results)->pluck('outcome')->sort()->values()->all());
            }
        } finally {
            $this->cleanupConcurrentState($request, $actor);
        }
    }

    public function test_two_process_assignment_versus_terminal_command_serializes_without_terminal_reopen(): void
    {
        $actor = $this->processor();
        $assignee = User::factory()->create();
        $this->grant($assignee, 'privacy.processRequests');
        $request = $this->verifiedRequest($actor);

        try {
            $results = $this->runConcurrentCommands($request, $actor, [
                ['action' => 'assign', 'assignee_id' => $assignee->id],
                ['action' => 'complete', 'notes' => 'Concurrent terminal completion.'],
            ]);

            $request->refresh();
            $assignmentOutcome = collect($results)->firstWhere('action', 'assign')['outcome'];

            $this->assertSame('success', collect($results)->firstWhere('action', 'complete')['outcome']);
            $this->assertContains($assignmentOutcome, ['conflict', 'success']);
            $this->assertSame('completed', $request->status);
            $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_COMPLETED));

            if ($assignmentOutcome === 'success') {
                $this->assertSame($assignee->id, $request->assigned_to_user_id);
                $this->assertNotNull($request->assigned_at);
                $this->assertSame(1, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
            } else {
                $this->assertNull($request->assigned_to_user_id);
                $this->assertNull($request->assigned_at);
                $this->assertSame(0, $this->auditCount($request, DataSubjectRequestLifecycleService::AUDIT_ASSIGNED));
            }
        } finally {
            $this->cleanupConcurrentState($request, $actor, [$assignee->id]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     * @return array<int, array{action: string, outcome: string}>
     */
    private function runConcurrentCommands(DataSubjectRequest $request, User $actor, array $commands): array
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $token = bin2hex(random_bytes(8));
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."dsr-release-{$token}";
        $readyPaths = [];
        $attemptPaths = [];
        $processes = [];

        $connection->commit();

        try {
            $connection->beginTransaction();
            DataSubjectRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            foreach ($commands as $index => $command) {
                $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."dsr-ready-{$index}-{$token}";
                $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."dsr-attempt-{$index}-{$token}";
                $processes[$index] = $this->startLifecycleWorker(
                    $request->id,
                    $actor->id,
                    $command,
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                    $database,
                );
            }

            $this->waitForFiles($readyPaths, 'Both DSR workers did not become ready.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, 'Both DSR workers did not reach the lifecycle service.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue(
                    $process->isRunning(),
                    trim($process->getErrorOutput()) ?: 'A DSR worker exited before the row lock was released.',
                );
            }

            $connection->commit();

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue(
                    $process->isSuccessful(),
                    trim($process->getErrorOutput()) ?: 'A DSR concurrency worker failed.',
                );
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
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

        }
    }

    /** @param array<int, int> $additionalUserIds */
    private function cleanupConcurrentState(
        DataSubjectRequest $request,
        User $actor,
        array $additionalUserIds = [],
    ): void {
        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $clientId = $request->client_id;
        $siteId = $clientId !== null
            ? DB::table('clients')->where('id', $clientId)->value('site_id')
            : null;
        $permissionId = Permission::query()->where('key', 'privacy.processRequests')->value('id');
        $userIds = array_values(array_unique([$actor->id, ...$additionalUserIds]));

        DB::table('audit_logs')
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->delete();
        DB::table('data_subject_requests')->where('id', $request->id)->delete();
        DB::table('permission_user')->whereIn('user_id', $userIds)->delete();
        DB::table('users')->whereIn('id', $userIds)->delete();
        if ($clientId !== null) {
            DB::table('clients')->where('id', $clientId)->delete();
        }
        if ($siteId !== null) {
            DB::table('sites')->where('id', $siteId)->delete();
        }
        if ($permissionId !== null) {
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        $connection->beginTransaction();
    }

    /** @param array<string, mixed> $command */
    private function startLifecycleWorker(
        int $requestId,
        int $actorId,
        array $command,
        string $readyPath,
        string $attemptPath,
        string $releasePath,
        string $database,
    ): Process {
        $worker = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$request = App\Models\DataSubjectRequest::query()->findOrFail((int) $argv[2]);
$actor = App\Models\User::query()->findOrFail((int) $argv[3]);
Illuminate\Support\Facades\Auth::setUser($actor);
$command = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
file_put_contents($argv[5], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[7])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timed out waiting for the DSR concurrency release barrier.');
    }
    usleep(10_000);
}
file_put_contents($argv[6], 'attempting');
$service = $app->make(App\Domain\Privacy\Services\DataSubjectRequestLifecycleService::class);
$outcome = 'success';
try {
    match ($command['action']) {
        'verify' => $service->verifyIdentity($request, $actor, $command['method']),
        'complete' => $service->complete($request, $actor, $command['notes']),
        'refuse' => $service->refuse($request, $actor, $command['reason'], $command['legal_basis']),
        'assign' => $service->assign($request, $actor, (int) $command['assignee_id']),
    };
} catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
    $outcome = 'conflict';
}
echo json_encode(['action' => $command['action'], 'outcome' => $outcome], JSON_THROW_ON_ERROR);
PHP;

        $process = new Process([
            PHP_BINARY,
            '-r',
            $worker,
            base_path(),
            (string) $requestId,
            (string) $actorId,
            base64_encode(json_encode($command, JSON_THROW_ON_ERROR)),
            $readyPath,
            $attemptPath,
            $releasePath,
        ], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @param array<int, string> $paths */
    private function waitForFiles(array $paths, string $message): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (collect($paths)->every(fn (string $path): bool => is_file($path))) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException($message);
    }

    /** @param array<string, mixed> $overrides */
    private function processor(array $overrides = []): User
    {
        $actor = User::factory()->create($overrides);
        $this->grant($actor, 'privacy.processRequests');
        $this->actingAs($actor);

        return $actor;
    }

    private function grant(User $user, string $permissionKey): void
    {
        $permission = Permission::firstOrCreate(
            ['key' => $permissionKey],
            ['description' => $permissionKey],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = []): DataSubjectRequest
    {
        return DataSubjectRequest::factory()->create(array_merge([
            'status' => 'identity_verification',
            'identity_verified' => 'pending',
            'identity_verified_at' => null,
            'verified_by_user_id' => null,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function verifiedRequest(User $verifier, array $overrides = []): DataSubjectRequest
    {
        return $this->request(array_merge([
            'status' => 'in_progress',
            'identity_verified' => 'verified',
            'identity_verified_at' => now()->subMinute(),
            'verified_by_user_id' => $verifier->id,
            'verification_method' => 'Existing verified identity evidence.',
        ], $overrides));
    }

    private function auditCount(DataSubjectRequest $request, string $action): int
    {
        return AuditLog::query()
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->where('action', $action)
            ->count();
    }

    private function terminalAuditCount(DataSubjectRequest $request): int
    {
        return AuditLog::query()
            ->where('auditable_type', $request->getMorphClass())
            ->where('auditable_id', $request->id)
            ->whereIn('action', [
                DataSubjectRequestLifecycleService::AUDIT_COMPLETED,
                DataSubjectRequestLifecycleService::AUDIT_REFUSED,
            ])
            ->count();
    }

    private function assertConflict(callable $command): void
    {
        $conflicted = false;
        try {
            $command();
        } catch (ConflictHttpException) {
            $conflicted = true;
        }

        $this->assertTrue($conflicted, 'The lifecycle command did not report a conflict.');
    }

    private function assertDenied(callable $command): void
    {
        $denied = false;
        try {
            $command();
        } catch (AuthorizationException) {
            $denied = true;
        }

        $this->assertTrue($denied, 'The lifecycle command accepted an unauthenticated or substituted actor.');
    }
}
