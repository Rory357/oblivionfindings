<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\Budget;
use App\Domain\Governance\Models\BudgetAdjustment;
use App\Domain\Governance\Models\BudgetLineItem;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Services\GovernanceNestedMutationService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceNestedBindingIntegrityTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_cross_meeting_agenda_ids_are_concealed_before_validation_or_side_effects(): void
    {
        $admin = $this->createAdminUser();
        $routeMeeting = $this->createMeeting($admin, ['title' => 'Route meeting']);
        $actualMeeting = $this->createMeeting($admin, ['title' => 'Actual meeting']);
        $foreignItem = $this->agendaItem($actualMeeting, ['title' => 'Protected agenda item']);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->put("/governance/meetings/{$routeMeeting->id}/agenda/{$foreignItem->id}", [
                'duration_minutes' => 1,
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete("/governance/meetings/{$routeMeeting->id}/agenda/{$foreignItem->id}")
            ->assertNotFound();

        $this->assertSame('Protected agenda item', $foreignItem->fresh()->title);
        $this->assertSame($actualMeeting->id, $foreignItem->fresh()->governance_meeting_id);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_actual_locked_meeting_is_authorized_before_child_resolution(): void
    {
        $admin = $this->createAdminUser();
        $lockedMeeting = $this->createMeeting($admin, [
            'locked_at' => now(),
            'locked_by' => $admin->id,
        ]);
        $otherMeeting = $this->createMeeting($admin);
        $otherItem = $this->agendaItem($otherMeeting);

        $this->actingAs($admin)
            ->put("/governance/meetings/{$lockedMeeting->id}/agenda/{$otherItem->id}", [
                'title' => 'Must never be applied',
            ])
            ->assertForbidden();

        $this->assertNotSame('Must never be applied', $otherItem->fresh()->title);
    }

    public function test_cross_budget_line_ids_are_concealed_and_valid_mutation_recalculates_only_actual_parent(): void
    {
        $admin = $this->createAdminUser();
        $routeBudget = $this->createBudget($admin, ['total_budget' => 40]);
        $actualBudget = $this->createBudget($admin, ['total_budget' => 80]);
        $routeLine = $this->lineItem($routeBudget, ['budget_amount' => 40]);
        $foreignLine = $this->lineItem($actualBudget, ['budget_amount' => 80]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->put("/governance/budgets/{$routeBudget->id}/line-items/{$foreignLine->id}", [
                'budget_amount' => -1,
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete("/governance/budgets/{$routeBudget->id}/line-items/{$foreignLine->id}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("/governance/budgets/{$routeBudget->id}/adjust", [
                'budget_line_item_id' => $foreignLine->id,
                'adjustment_type' => 'increase',
                'amount' => 0,
                'reason' => 'This forged parent pairing must never be validated or created.',
            ])
            ->assertNotFound();

        $this->assertSame('80.00', $foreignLine->fresh()->budget_amount);
        $this->assertSame($actualBudget->id, $foreignLine->fresh()->budget_id);
        $this->assertSame('40.00', $routeBudget->fresh()->total_budget);
        $this->assertDatabaseCount('budget_adjustments', 0);
        $this->assertSame($auditCount, AuditLog::query()->count());

        $this->actingAs($admin)
            ->put("/governance/budgets/{$routeBudget->id}/line-items/{$routeLine->id}", [
                'budget_amount' => 65,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('65.00', $routeLine->fresh()->budget_amount);
        $this->assertSame('65.00', $routeBudget->fresh()->total_budget);
        $this->assertSame('80.00', $actualBudget->fresh()->total_budget);
    }

    public function test_approved_budget_blocks_direct_line_mutation_but_adjustment_decision_uses_approve_capability(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['status' => 'approved', 'total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 25]);

        $this->actingAs($admin)
            ->put("/governance/budgets/{$budget->id}/line-items/{$line->id}", [
                'budget_amount' => -1,
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post("/governance/budgets/{$budget->id}/adjustments/{$adjustment->id}/approve")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('125.00', $line->fresh()->budget_amount);
        $this->assertSame('125.00', $budget->fresh()->total_budget);
        $this->assertSame('approved', $adjustment->fresh()->status);
    }

    public function test_cross_budget_adjustment_ids_are_concealed_before_decision_validation(): void
    {
        $admin = $this->createAdminUser();
        $routeBudget = $this->createBudget($admin);
        $actualBudget = $this->createBudget($admin, ['total_budget' => 100]);
        $foreignLine = $this->lineItem($actualBudget, ['budget_amount' => 100]);
        $foreignAdjustment = $this->adjustment($actualBudget, $foreignLine, ['amount' => 15]);
        $auditCount = AuditLog::query()->count();

        $this->actingAs($admin)
            ->post("/governance/budgets/{$routeBudget->id}/adjustments/{$foreignAdjustment->id}/approve")
            ->assertNotFound();

        $this->actingAs($admin)
            ->post("/governance/budgets/{$routeBudget->id}/adjustments/{$foreignAdjustment->id}/reject", [])
            ->assertNotFound();

        $this->assertSame('submitted', $foreignAdjustment->fresh()->status);
        $this->assertSame('100.00', $foreignLine->fresh()->budget_amount);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_adjustment_approval_replay_applies_one_effect_and_conflicting_terminal_decision_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 20]);

        $service = app(GovernanceNestedMutationService::class);
        $service->approveBudgetAdjustment($admin, $budget, $adjustment);
        $auditCount = AuditLog::query()->count();
        $approvedAt = $adjustment->fresh()->approved_at?->toISOString();

        $service->approveBudgetAdjustment($admin, $budget->fresh(), $adjustment->fresh());

        $this->assertSame('120.00', $line->fresh()->budget_amount);
        $this->assertSame('120.00', $budget->fresh()->total_budget);
        $this->assertSame($approvedAt, $adjustment->fresh()->approved_at?->toISOString());
        $this->assertSame($auditCount, AuditLog::query()->count());

        try {
            $service->rejectBudgetAdjustment($admin, $budget->fresh(), $adjustment->fresh(), 'Conflicting replay');
            $this->fail('A conflicting terminal decision was accepted.');
        } catch (ValidationException) {
            $this->assertSame('approved', $adjustment->fresh()->status);
            $this->assertSame('120.00', $line->fresh()->budget_amount);
        }
    }

    public function test_adjustment_rejection_replay_is_stable_and_conflicting_approval_is_rejected(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 20]);
        $reviewNotes = 'The proposed increase is not approved.';
        $service = app(GovernanceNestedMutationService::class);

        $service->rejectBudgetAdjustment($admin, $budget, $adjustment, $reviewNotes);
        $auditCount = AuditLog::query()->count();
        $rejectedAt = $adjustment->fresh()->approved_at?->toISOString();

        $service->rejectBudgetAdjustment(
            $admin,
            $budget->fresh(),
            $adjustment->fresh(),
            $reviewNotes,
        );

        $this->assertSame('rejected', $adjustment->fresh()->status);
        $this->assertSame($rejectedAt, $adjustment->fresh()->approved_at?->toISOString());
        $this->assertSame($auditCount, AuditLog::query()->count());
        $this->assertSame('100.00', $line->fresh()->budget_amount);
        $this->assertSame('100.00', $budget->fresh()->total_budget);

        try {
            $service->approveBudgetAdjustment($admin, $budget->fresh(), $adjustment->fresh());
            $this->fail('A conflicting terminal approval was accepted.');
        } catch (ValidationException) {
            $this->assertSame('rejected', $adjustment->fresh()->status);
            $this->assertSame('100.00', $line->fresh()->budget_amount);
        }
    }

    public function test_adjustment_failure_rolls_back_child_parent_and_audit_effects(): void
    {
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 10]);
        $auditCount = AuditLog::query()->count();

        $service = new class(app(UserSiteAccessService::class)) extends GovernanceNestedMutationService
        {
            protected function afterNestedMutation(
                string $mutation,
                Model $parent,
                ?Model $child = null,
            ): void {
                if ($mutation === 'budget_adjustment.approved') {
                    throw new RuntimeException('Injected nested-mutation failure.');
                }
            }
        };

        try {
            $service->approveBudgetAdjustment($admin, $budget, $adjustment);
            $this->fail('The injected rollback failure did not run.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected nested-mutation failure.', $exception->getMessage());
        }

        $this->assertSame('submitted', $adjustment->fresh()->status);
        $this->assertSame('100.00', $line->fresh()->budget_amount);
        $this->assertSame('100.00', $budget->fresh()->total_budget);
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_bulk_actuals_rejects_duplicate_and_foreign_lines_atomically(): void
    {
        $admin = $this->createAdminUser();
        $routeBudget = $this->createBudget($admin);
        $actualBudget = $this->createBudget($admin);
        $localLine = $this->lineItem($routeBudget, ['actual_amount' => 5]);
        $foreignLine = $this->lineItem($actualBudget, ['actual_amount' => 7]);

        try {
            app(GovernanceNestedMutationService::class)->recordBudgetActuals($admin, $routeBudget, [
                ['id' => $localLine->id, 'actual_amount' => 50],
                ['id' => $localLine->id, 'actual_amount' => 60],
            ]);
            $this->fail('Duplicate budget actual rows were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('actuals', $exception->errors());
        }

        $this->assertSame('5.00', $localLine->fresh()->actual_amount);

        $this->actingAs($admin)
            ->post("/governance/budgets/{$routeBudget->id}/record-actuals", [
                'actuals' => [
                    ['id' => $localLine->id, 'actual_amount' => 50],
                    ['id' => $foreignLine->id, 'actual_amount' => 70],
                ],
            ])
            ->assertNotFound();

        $this->assertSame('5.00', $localLine->fresh()->actual_amount);
        $this->assertSame('7.00', $foreignLine->fresh()->actual_amount);

        $this->actingAs($admin)
            ->post("/governance/budgets/{$routeBudget->id}/record-actuals", [
                'actuals' => [
                    ['id' => $localLine->id, 'actual_amount' => 50],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('50.00', $localLine->fresh()->actual_amount);
        $this->assertSame('-50.00', $localLine->fresh()->variance_amount);
        $this->assertSame('7.00', $foreignLine->fresh()->actual_amount);
    }

    public function test_allocation_retains_parent_equality_and_requires_site_scope_plus_action_capability(): void
    {
        $homeSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin);
        $otherBudget = $this->createBudget($admin);
        $line = $this->lineItem($budget);
        $foreignBudgetLine = $this->lineItem($otherBudget);
        $actor = $this->siteActor($homeSite, [
            'governance.budgets.view',
            'governance.budgets.create',
        ]);

        $payload = [
            'budget_line_item_id' => $line->id,
            'site_id' => $foreignSite->id,
            'period_year_month' => now()->format('Y-m'),
            'category' => 'operations',
            'allocated_amount' => 25,
        ];

        $this->actingAs($actor)
            ->post("/governance/budgets/{$budget->id}/allocations", $payload)
            ->assertNotFound();
        $this->assertDatabaseCount('budget_allocations', 0);

        $this->grant($actor, 'reports.viewAny');
        $this->actingAs($actor->fresh())
            ->post("/governance/budgets/{$budget->id}/allocations", [
                ...$payload,
                'budget_line_item_id' => $foreignBudgetLine->id,
                'allocated_amount' => -1,
            ])
            ->assertNotFound();
        $this->assertDatabaseCount('budget_allocations', 0);

        $this->actingAs($actor->fresh())
            ->post("/governance/budgets/{$budget->id}/allocations", $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $allocation = $budget->allocations()->sole();
        $this->assertSame($foreignSite->id, $allocation->site_id);

        $localOnly = $this->siteActor($homeSite, [
            'governance.budgets.view',
            'governance.budgets.create',
        ]);
        $this->actingAs($localOnly)
            ->put("/governance/budgets/{$budget->id}/allocations/{$allocation->id}", [
                'allocated_amount' => -1,
            ])
            ->assertNotFound();
        $this->assertSame('25.00', $allocation->fresh()->allocated_amount);

        $this->actingAs($actor->fresh())
            ->put("/governance/budgets/{$otherBudget->id}/allocations/{$allocation->id}", [
                'allocated_amount' => 99,
            ])
            ->assertNotFound();
        $this->assertSame('25.00', $allocation->fresh()->allocated_amount);

        $reportsOnly = $this->siteActor($homeSite, [
            'governance.budgets.view',
            'reports.viewAny',
        ]);
        $this->actingAs($reportsOnly)
            ->post("/governance/budgets/{$otherBudget->id}/allocations", [
                ...$payload,
                'budget_line_item_id' => $foreignBudgetLine->id,
            ])
            ->assertForbidden();
    }

    public function test_concurrent_adjustment_approval_replay_applies_one_line_effect_on_mysql(): void
    {
        $this->requireMySql();
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['status' => 'approved', 'total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 25]);

        $statuses = $this->concurrentAdjustmentRound($budget, $adjustment, $admin, ['approve', 'approve']);

        $this->assertSame(['approved', 'approved'], $statuses);
        $this->assertSame('approved', $adjustment->fresh()->status);
        $this->assertSame('125.00', $line->fresh()->budget_amount);
        $this->assertSame('125.00', $budget->fresh()->total_budget);
    }

    public function test_concurrent_approve_and_reject_reach_one_terminal_decision_on_mysql(): void
    {
        $this->requireMySql();
        $admin = $this->createAdminUser();
        $budget = $this->createBudget($admin, ['status' => 'approved', 'total_budget' => 100]);
        $line = $this->lineItem($budget, ['budget_amount' => 100]);
        $adjustment = $this->adjustment($budget, $line, ['amount' => 25]);

        $statuses = $this->concurrentAdjustmentRound($budget, $adjustment, $admin, ['approve', 'reject']);

        $this->assertContains('conflict', $statuses);
        $this->assertCount(1, array_intersect($statuses, ['approved', 'rejected']));
        $this->assertContains($adjustment->fresh()->status, ['approved', 'rejected']);
        $expectedAmount = $adjustment->fresh()->status === 'approved' ? '125.00' : '100.00';
        $this->assertSame($expectedAmount, $line->fresh()->budget_amount);
        $this->assertSame($expectedAmount, $budget->fresh()->total_budget);
    }

    private function agendaItem(GovernanceMeeting $meeting, array $overrides = []): MeetingAgendaItem
    {
        return $meeting->agendaItems()->create(array_merge([
            'order' => 1,
            'title' => 'Agenda item',
            'duration_minutes' => 15,
            'item_type' => 'standard',
            'is_confidential' => false,
        ], $overrides));
    }

    private function lineItem(Budget $budget, array $overrides = []): BudgetLineItem
    {
        return $budget->lineItems()->create(array_merge([
            'category' => 'operations',
            'description' => 'Governance operating line',
            'budget_amount' => 100,
            'forecast_amount' => 100,
            'actual_amount' => 0,
        ], $overrides));
    }

    private function adjustment(
        Budget $budget,
        BudgetLineItem $lineItem,
        array $overrides = [],
    ): BudgetAdjustment {
        return $budget->adjustments()->create(array_merge([
            'budget_line_item_id' => $lineItem->id,
            'adjustment_type' => 'increase',
            'amount' => 10,
            'reason' => 'Governance-approved adjustment',
            'status' => 'submitted',
            'threshold_applies' => false,
            'proposed_by' => $budget->created_by,
            'proposed_at' => now(),
        ], $overrides));
    }

    /** @param array<int, string> $permissions */
    private function siteActor(Site $site, array $permissions): User
    {
        $actor = User::factory()->create([
            'approved_at' => now(),
            'role' => 'manager',
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        foreach ($permissions as $permission) {
            $this->grant($actor, $permission);
        }

        return $actor;
    }

    private function grant(User $actor, string $permissionKey): void
    {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => str_replace('.', ' ', $permissionKey),
                'group' => explode('.', $permissionKey)[0],
            ],
        );
        $actor->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
        $actor->unsetRelations();
    }

    private function requireMySql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This concurrency assertion requires MySQL row locks.');
        }
    }

    /**
     * @param  array{0: string, 1: string}  $actions
     * @return array<int, string>
     */
    private function concurrentAdjustmentRound(
        Budget $budget,
        BudgetAdjustment $adjustment,
        User $actor,
        array $actions,
    ): array {
        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }

        $token = bin2hex(random_bytes(8));
        $releasePath = sys_get_temp_dir().DIRECTORY_SEPARATOR."gov-nested-release-{$token}";
        $readyPaths = [];
        $attemptPaths = [];
        $processes = [];
        $database = (string) $connection->getDatabaseName();

        $connection->beginTransaction();
        Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

        try {
            foreach ($actions as $index => $action) {
                $readyPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."gov-nested-ready-{$index}-{$token}";
                $attemptPaths[$index] = sys_get_temp_dir().DIRECTORY_SEPARATOR."gov-nested-attempt-{$index}-{$token}";
                $processes[] = new Process([
                    PHP_BINARY,
                    base_path('tests/Support/GovernanceNestedMutationWorker.php'),
                    $database,
                    $action,
                    (string) $budget->id,
                    (string) $adjustment->id,
                    (string) $actor->id,
                    $readyPaths[$index],
                    $attemptPaths[$index],
                    $releasePath,
                ], base_path(), timeout: 30);
                $processes[$index]->start();
            }

            $this->waitForFiles($readyPaths, $processes, 'Workers did not become ready.');
            touch($releasePath);
            $this->waitForFiles($attemptPaths, $processes, 'Workers did not reach the mutation attempt.');
            usleep(250_000);

            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'A worker exited before the parent lock was released.');
            }

            $connection->commit();
            $statuses = [];

            foreach ($processes as $process) {
                $process->wait();
                if (! $process->isSuccessful()) {
                    throw new RuntimeException(trim($process->getErrorOutput()) ?: 'A governance mutation worker failed.');
                }

                $statuses[] = json_decode(
                    trim($process->getOutput()),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                )['status'];
            }

            sort($statuses);

            return $statuses;
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

            $connection->beginTransaction();
        }
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<int, Process>  $processes
     */
    private function waitForFiles(array $paths, array $processes, string $message): void
    {
        $deadline = microtime(true) + 15;

        while (collect($paths)->contains(fn (string $path): bool => ! is_file($path))) {
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail(trim($process->getErrorOutput()) ?: $message);
                }
            }

            if (microtime(true) >= $deadline) {
                $this->fail($message);
            }

            usleep(20_000);
        }
    }
}
