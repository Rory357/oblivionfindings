<?php

namespace Tests\Feature\Timesheets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Timesheets\TimesheetPayrollAdjustmentReceipt;
use App\Domain\Shifts\Timesheets\TimesheetPayrollAdjustmentService;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\Support\TimesheetPayrollAdjustmentProcess;
use Tests\TestCase;

class TimesheetPayrollAdjustmentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Site $foreignSite;

    private Client $client;

    private User $reviewer;

    private User $worker;

    private bool $committed = false;

    private array $children = [];

    private array $prefixes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->foreignSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id]);
        $this->reviewer = $this->actor(['timesheets.viewAny', 'timesheets.approve']);
        $this->worker = $this->actor(['timesheets.viewAssigned']);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->children as $child) {
                if ($child->isRunning()) {
                    $child->stop(1);
                }
                $this->assertFalse($child->isRunning());
            }
            foreach ($this->prefixes as $prefix) {
                foreach (['-ready.writing', '-ready.json', '-release', '-services.php', '-packages.php', '-absent-config.php', '-absent-routes.php'] as $suffix) {
                    if (is_file($prefix.$suffix)) {
                        unlink($prefix.$suffix);
                    }
                }
            }
            DB::purge('payroll_adjustment_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public function test_queue_scope_keeps_all_existing_fields_but_withholds_foreign_pay_details_and_reports_bypass(): void
    {
        $visible = $this->amendment();
        $foreign = $this->amendment(false, ['site_id' => $this->foreignSite->id, 'shift_site_id' => $this->foreignSite->id, 'client_id' => null]);
        $this->grant($this->reviewer, 'reports.viewAny');
        $before = $this->state();
        $this->actingAs($this->reviewer->fresh())->get('/operations/timesheets/payroll-adjustments')
            ->assertOk()->assertInertia(fn ($page) => $page->component('operations/timesheets/payroll-adjustments')
            ->where('canProcess', true)->where('evidence.timezone', 'Pacific/Auckland')
            ->has('amendments.data', 1)->where('amendments.data.0.id', $visible->id)
            ->where('amendments.data.0.reason', $visible->reason)
            ->where('amendments.data.0.original_values', $visible->original_values)
            ->where('amendments.data.0.proposed_values', $visible->proposed_values)
            ->where('amendments.data.0.payroll_reference', 'Recorded external run'));
        $this->assertNotSame($visible->id, $foreign->id);
        $this->assertSame($before, $this->state());
    }

    public function test_forged_foreign_amendment_and_read_only_grant_cannot_process(): void
    {
        $foreign = $this->amendment(false, ['site_id' => $this->foreignSite->id, 'shift_site_id' => $this->foreignSite->id, 'client_id' => null]);
        $this->grant($this->reviewer, 'reports.viewAny');
        $before = $this->state();
        $this->actingAs($this->reviewer->fresh())->post($this->url($foreign))->assertForbidden()->assertSessionMissing('timesheet_payroll_adjustment_result');
        $this->actingAs($this->worker)->post($this->url($foreign))->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    public static function grants(): array
    {
        return ['approve' => ['timesheets.approve', 'approved'], 'manage_any_retained_paid' => ['timesheets.manageAny', 'paid']];
    }

    #[DataProvider('grants')]
    public function test_root_marker_receipt_replay_and_all_payroll_values_are_preserved(string $grant, string $parentStatus): void
    {
        $actor = $this->actor([$grant]);
        $row = $this->amendment();
        // Retained paid state is a preservation fixture, never invented bank-acceptance evidence.
        DB::table('timesheets')->where('id', $row->timesheet_id)->update(['status' => $parentStatus]);
        $this->commitFixtures();
        $protected = $this->protectedState();
        $original = $row->fresh()->getRawOriginal();
        $this->withSession(['timesheet_payroll_adjustment_result' => ['action' => 'old']]);
        $this->actingAs($actor)->post($this->url($row))->assertRedirect()->assertSessionHas('success', 'Payroll adjustment marked as processed.');
        $receipt = session('timesheet_payroll_adjustment_result');
        $this->assertSame(['action' => 'process_payroll_adjustment', 'actor_id' => $actor->id, 'amendment_id' => $row->id,
            'timesheet_id' => $row->timesheet_id, 'changed' => true, 'outcome' => 'recorded_external_processing',
            'processing_method' => 'external', 'applied_at' => '2026-10-08T00:00:00.000Z'], $receipt);
        $saved = $row->fresh();
        $this->assertSame('2026-10-08T00:00:00.000000Z', $saved->applied_at->toISOString());
        foreach ($original as $key => $value) {
            if (! in_array($key, ['applied_at', 'updated_at'], true)) {
                $this->assertSame($value, $saved->getRawOriginal($key));
            }
        }
        $this->assertSame($protected, $this->protectedState());
        $this->assertSame($parentStatus, Timesheet::findOrFail($row->timesheet_id)->status);
        $audit = AuditLog::where('action', 'timesheet.amendment.payroll_processed')->sole();
        $this->assertSame($actor->id, (int) $audit->user_id);
        $this->assertSame($row->id, $audit->meta['amendment_id']);
        $this->assertSame('external', $audit->meta['processing_method']);
        $beforeReplay = $this->state();
        Carbon::setTestNow(now()->addHour());
        $this->actingAs($actor)->post($this->url($row))->assertRedirect()->assertSessionHas('success', 'This adjustment has already been marked as processed.');
        $this->assertFalse(session('timesheet_payroll_adjustment_result.changed'));
        $this->assertSame('already_recorded', session('timesheet_payroll_adjustment_result.outcome'));
        $this->assertSame($receipt['applied_at'], session('timesheet_payroll_adjustment_result.applied_at'));
        $this->assertSame($beforeReplay, $this->state());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function aliasGrants(): array
    {
        return ['approve_team' => ['timesheets.approve', 'hr.time.approveTeam'],
            'manage' => ['timesheets.manageAny', 'hr.time.manage']];
    }

    #[DataProvider('aliasGrants')]
    public function test_existing_alias_only_grant_retains_root_processing_authority(string $canonical, string $alias): void
    {
        $actor = $this->actor([$alias]);
        $row = $this->amendment();
        $this->assertTrue($actor->canDo($canonical));
        $this->assertFalse($actor->roles()->whereHas('permissions', fn ($query) => $query->where('key', $canonical))->exists());
        $this->commitFixtures();
        $protected = $this->protectedState();
        $this->actingAs($actor)->post($this->url($row))->assertRedirect()
            ->assertSessionHas('success', 'Payroll adjustment marked as processed.')
            ->assertSessionHas('timesheet_payroll_adjustment_result.actor_id', $actor->id)
            ->assertSessionHas('timesheet_payroll_adjustment_result.changed', true)
            ->assertSessionHas('timesheet_payroll_adjustment_result.outcome', 'recorded_external_processing');
        $this->assertNotNull($row->fresh()->applied_at);
        $this->assertSame($protected, $this->protectedState());
        $audit = AuditLog::where('action', 'timesheet.amendment.payroll_processed')->sole();
        $this->assertSame($actor->id, (int) $audit->user_id);
        $this->assertSame($row->id, $audit->meta['amendment_id']);
        $beforeReplay = $this->state();
        $this->actingAs($actor)->post($this->url($row))->assertRedirect()
            ->assertSessionHas('timesheet_payroll_adjustment_result.changed', false)
            ->assertSessionHas('timesheet_payroll_adjustment_result.outcome', 'already_recorded');
        $this->assertSame($beforeReplay, $this->state());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    #[DataProvider('aliasGrants')]
    public function test_current_alias_deny_overrides_canonical_role_despite_a_primed_rr_snapshot(string $canonical, string $alias): void
    {
        $actor = $this->actor([$canonical]);
        $permission = Permission::firstOrCreate(['key' => $alias], ['description' => $alias, 'group' => 'timesheets', 'module' => 'Operations']);
        $actor->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        $row = $this->amendment();
        $this->commitFixtures();
        $writer = $this->writer();
        $overrides = fn (Connection $connection): array => $connection->table('permission_user')->orderBy('permission_id')->orderBy('user_id')
            ->get()->map(fn ($value) => (array) $value)->all();
        DB::beginTransaction();
        try {
            $staleActor = $actor->fresh()->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            $bound = $row->fresh();
            $old = $this->state();
            $oldOverrides = $overrides(DB::connection());
            $this->assertTrue($staleActor->canDo($canonical));
            $this->assertSame(1, $writer->table('permission_user')->where('permission_id', $permission->id)->where('user_id', $actor->id)->update(['allowed' => false]));
            $current = $this->state($writer);
            $currentOverrides = $overrides($writer);
            $this->assertNotSame($oldOverrides, $currentOverrides);
            $this->assertFalse((bool) $writer->table('permission_user')->where('permission_id', $permission->id)->where('user_id', $actor->id)->value('allowed'));
            $this->assertSame($oldOverrides, $overrides(DB::connection()));
            $this->assertSame($old, $this->state());
            $this->assertTrue($staleActor->canDo($canonical));
            try {
                app(TimesheetPayrollAdjustmentService::class)->process($bound, $staleActor);
                $this->fail('A current alias deny must override the retained canonical role grant.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($current, $this->state($writer));
        $this->assertSame($currentOverrides, $overrides($writer));
        $this->assertNull($row->fresh()->applied_at);
        $this->assertSame(0, AuditLog::where('action', 'timesheet.amendment.payroll_processed')->count());
    }

    public static function invalidStates(): array
    {
        return ['pending' => [['status' => 'pending'], 'Only approved amendments can be marked as processed.'],
            'not_required' => [['payroll_adjustment_required' => false], 'This amendment does not require payroll adjustment.']];
    }

    #[DataProvider('invalidStates')]
    public function test_existing_status_blockers_clear_only_the_stale_outcome(array $values, string $message): void
    {
        $row = $this->amendment();
        $row->update($values);
        $this->commitFixtures();
        $before = $this->state();
        $this->withSession(['timesheet_payroll_adjustment_result' => ['action' => 'old']]);
        $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionHas('error', $message)
            ->assertSessionMissing('timesheet_payroll_adjustment_result');
        $this->assertSame($before, $this->state());
    }

    public static function boundaries(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    #[DataProvider('boundaries')]
    public function test_nested_commands_never_publish_receipts_at_the_outer_commit_or_rollback(bool $commit): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $before = $this->state();
        $originalManager = app('db.transactions');
        $manager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        try {
            DB::beginTransaction();
            $this->withSession(['timesheet_payroll_adjustment_result' => ['action' => 'old']]);
            $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionMissing('timesheet_payroll_adjustment_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertNotNull($row->fresh()->applied_at);
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertNull(session('timesheet_payroll_adjustment_result'));
            if ($commit) {
                $this->assertNotNull($row->fresh()->applied_at);
                $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionHas('timesheet_payroll_adjustment_result.changed', false);
            } else {
                $this->assertSame($before, $this->state());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            app()->instance('db.transactions', $originalManager);
            DB::connection()->setTransactionManager($originalManager);
        }
    }

    public static function drift(): array
    {
        return array_combine(['actor_approval', 'exact_grant', 'actor_profile', 'actor_end', 'actor_membership',
            'site_inactive', 'site_archived', 'client_site', 'worker_approval', 'worker_profile', 'worker_membership',
            'worker_portal', 'shift_owner', 'timesheet_owner'], array_map(fn ($value) => [$value], ['actor_approval', 'exact_grant',
                'actor_profile', 'actor_end', 'actor_membership', 'site_inactive', 'site_archived', 'client_site', 'worker_approval',
                'worker_profile', 'worker_membership', 'worker_portal', 'shift_owner', 'timesheet_owner']));
    }

    #[DataProvider('drift')]
    public function test_primed_rr_snapshots_cannot_authorize_independently_committed_current_drift(string $drift): void
    {
        $row = $this->amendment(true);
        $other = $this->actor([]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        try {
            $actor = $this->reviewer->fresh()->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            $bound = $row->fresh();
            $old = $this->state();
            $profile = HrEmployeeProfile::where('user_id', $this->reviewer->id)->firstOrFail();
            $workerProfile = HrEmployeeProfile::where('user_id', $this->worker->id)->firstOrFail();
            match ($drift) {
                'actor_approval' => $writer->table('users')->where('id', $actor->id)->update(['approved_at' => null]),
                'exact_grant' => $writer->table('role_permission')->where('role_id', $actor->roles->sole()->id)->delete(),
                'actor_profile' => $writer->table('hr_employee_profiles')->where('id', $profile->id)->update(['is_active' => false]),
                'actor_end' => $writer->table('hr_employee_profiles')->where('id', $profile->id)->update(['end_date' => '2026-10-07']),
                'actor_membership' => $writer->table('hr_employee_profiles')->where('id', $profile->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => '[]']),
                'site_inactive' => $writer->table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
                'site_archived' => $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true, 'archived_at' => now()]),
                'client_site' => $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $this->foreignSite->id]),
                'worker_approval' => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
                'worker_profile' => $writer->table('hr_employee_profiles')->where('id', $workerProfile->id)->update(['is_active' => false]),
                'worker_membership' => $writer->table('hr_employee_profiles')->where('id', $workerProfile->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => '[]']),
                'worker_portal' => $writer->table('users')->where('id', $this->worker->id)->update(['role' => 'client']),
                'shift_owner' => $writer->table('shifts')->where('id', $row->timesheet->shift_id)->update(['user_id' => $other->id]),
                'timesheet_owner' => $writer->table('timesheets')->where('id', $row->timesheet_id)->update(['user_id' => $other->id]),
            };
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $this->assertSame($old, $this->state());
            try {
                app(TimesheetPayrollAdjustmentService::class)->process($bound, $actor);
                $this->fail('Current evidence must deny this committed drift.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        } finally {
            DB::rollBack();
        }
        $this->assertSame($current, $this->state());
        $this->assertNull($row->fresh()->applied_at);
        $this->assertSame(0, AuditLog::where('action', 'timesheet.amendment.payroll_processed')->count());
    }

    public function test_bound_parent_retarget_is_rejected_without_touching_either_timesheet(): void
    {
        $row = $this->amendment();
        $replacement = $this->amendment();
        $this->commitFixtures();
        $writer = $this->writer();
        $writer->table('timesheet_amendments')->where('id', $row->id)->update(['timesheet_id' => $replacement->timesheet_id]);
        $before = $this->state();
        try {
            app(TimesheetPayrollAdjustmentService::class)->process($row, $this->reviewer);
            $this->fail('Bound parent drift must deny.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->state());
    }

    public static function sourceKinds(): array
    {
        return ['manual_no_client' => ['manual_no_client'], 'client_site_fallback' => ['client_site_fallback'],
            'shift_site_fallback' => ['shift_site_fallback'], 'manual_owner_profile_not_required' => ['manual_owner_profile_not_required']];
    }

    #[DataProvider('sourceKinds')]
    public function test_existing_manual_and_legacy_canonical_site_fallbacks_remain(string $kind): void
    {
        $row = $this->amendment($kind === 'shift_site_fallback');
        if ($kind === 'manual_no_client') {
            DB::table('timesheets')->where('id', $row->timesheet_id)->update(['client_id' => null]);
        } elseif (in_array($kind, ['client_site_fallback', 'shift_site_fallback'], true)) {
            DB::table('timesheets')->where('id', $row->timesheet_id)->update(['site_id' => null, 'shift_site_id' => null]);
        } else {
            DB::table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['is_active' => false]);
        }
        $this->commitFixtures();
        $protected = $this->protectedState();
        $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionHas('timesheet_payroll_adjustment_result.changed', true);
        $this->assertSame($protected, $this->protectedState());
    }

    public static function failures(): array
    {
        return ['save_veto' => ['save_veto'], 'altered_marker' => ['altered_marker'], 'audit_failure' => ['audit_failure'], 'audit_veto' => ['audit_veto']];
    }

    #[DataProvider('failures')]
    public function test_marker_and_mandatory_audit_failures_roll_back_all_persisted_state(string $failure): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $before = $this->state();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        if (in_array($failure, ['save_veto', 'altered_marker'], true)) {
            $dispatcher->listen('eloquent.saving: '.TimesheetAmendment::class, function ($amendment) use ($failure) {
                if ($amendment->applied_at !== null) {
                    if ($failure === 'save_veto') {
                        return false;
                    }
                    $amendment->applied_at = null;
                }
            });
        } else {
            $dispatcher->listen('eloquent.creating: '.AuditLog::class, function ($audit) use ($failure) {
                if ($audit->action === 'timesheet.amendment.payroll_processed') {
                    if ($failure === 'audit_veto') {
                        return false;
                    }
                    throw new \RuntimeException('Synthetic processing audit storage failure');
                }
            });
        }
        Model::setEventDispatcher($dispatcher);
        try {
            app(TimesheetPayrollAdjustmentService::class)->process($row, $this->reviewer);
            $this->fail('An unpersisted marker or mandatory audit must deny.');
        } catch (HttpException $exception) {
            $this->assertNotSame('audit_failure', $failure);
            $this->assertSame(409, $exception->getStatusCode());
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit_failure', $failure);
            $this->assertSame('Synthetic processing audit storage failure', $exception->getMessage());
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($before, $this->state());
    }

    public static function holders(): array
    {
        return ['site' => ['sites'], 'role' => ['roles']];
    }

    #[DataProvider('holders')]
    public function test_real_held_current_evidence_is_recoverable_without_partial_processing(string $table): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $writer = $this->writer();
        $before = $this->state();
        $writer->beginTransaction();
        try {
            $id = $table === 'sites' ? $this->site->id : $this->reviewer->roles()->sole()->id;
            $writer->table($table)->where('id', $id)->lockForUpdate()->first();
            try {
                app(TimesheetPayrollAdjustmentService::class)->process($row, $this->reviewer);
                $this->fail('Held NOWAIT evidence must be recoverable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('payroll_adjustment', $exception->errors());
            }
        } finally {
            $writer->rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_postcommit_projection_and_logger_fault_do_not_turn_a_saved_marker_into_failure(): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        app()->instance(TimesheetPayrollAdjustmentReceipt::class, new class extends TimesheetPayrollAdjustmentReceipt
        {
            protected function isPhysicalRoot(): bool
            {
                static $calls = 0;
                if (++$calls > 1) {
                    throw new \RuntimeException('Synthetic presentation boundary fault');
                }

                return parent::isPhysicalRoot();
            }
        });
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('Synthetic logger fault'));
        $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionHas('success', 'Payroll adjustment marked as processed.')
            ->assertSessionMissing('timesheet_payroll_adjustment_result');
        $this->assertNotNull($row->fresh()->applied_at);
        $this->assertSame(1, AuditLog::where('action', 'timesheet.amendment.payroll_processed')->count());
    }

    public function test_raw_pdo_enclosing_transaction_never_receives_a_committed_receipt(): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $before = $this->state();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->actingAs($this->reviewer)->post($this->url($row))->assertStatus(500)->assertSessionMissing('timesheet_payroll_adjustment_result');
        } finally {
            $pdo->rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_current_scoped_queue_pagination_is_deterministic_and_excludes_hidden_totals(): void
    {
        $ids = [];
        for ($i = 0; $i < 21; $i++) {
            $ids[] = $this->amendment()->id;
        }
        $this->amendment(false, ['site_id' => $this->foreignSite->id, 'shift_site_id' => $this->foreignSite->id, 'client_id' => null]);
        $this->actingAs($this->reviewer)->get('/operations/timesheets/payroll-adjustments')->assertInertia(fn ($page) => $page
            ->has('amendments.data', 20)->where('amendments.total', 21)->where('amendments.data.0.id', $ids[0])->where('amendments.data.19.id', $ids[19]));
        $this->get('/operations/timesheets/payroll-adjustments?page=2')->assertInertia(fn ($page) => $page
            ->has('amendments.data', 1)->where('amendments.total', 21)->where('amendments.data.0.id', $ids[20]));
    }

    public static function brokenSources(): array
    {
        return ['site_disagreement' => ['site_disagreement'], 'missing_site_provenance' => ['missing_site_provenance'],
            'deleted_client' => ['deleted_client'], 'shift_client' => ['shift_client']];
    }

    #[DataProvider('brokenSources')]
    public function test_invalid_current_source_tuples_cannot_be_processed(string $kind): void
    {
        $row = $this->amendment($kind === 'shift_client');
        if ($kind === 'site_disagreement') {
            DB::table('timesheets')->where('id', $row->timesheet_id)->update(['site_id' => $this->foreignSite->id]);
        } elseif ($kind === 'missing_site_provenance') {
            DB::table('timesheets')->where('id', $row->timesheet_id)->update(['site_id' => null, 'shift_site_id' => null, 'client_id' => null]);
        } elseif ($kind === 'deleted_client') {
            DB::table('clients')->where('id', $this->client->id)->update(['deleted_at' => now()]);
        } else {
            $client = Client::factory()->create(['site_id' => $this->site->id]);
            DB::table('shifts')->where('id', $row->timesheet->shift_id)->update(['client_id' => $client->id]);
        }
        $this->commitFixtures();
        $before = $this->state();
        $this->actingAs($this->reviewer)->post($this->url($row))->assertForbidden()->assertSessionMissing('timesheet_payroll_adjustment_result');
        $this->assertSame($before, $this->state());
    }

    public function test_other_database_errors_propagate_and_roll_back_instead_of_becoming_retry_validation(): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $before = $this->state();
        $previous = new \PDOException('Synthetic non-NOWAIT database failure');
        $previous->errorInfo = ['HY000', 1205, 'Synthetic lock timeout'];
        $exception = new QueryException('mysql', 'SELECT synthetic', [], $previous);
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.creating: '.AuditLog::class, function ($audit) use ($exception): void {
            if ($audit->action === 'timesheet.amendment.payroll_processed') {
                throw $exception;
            }
        });
        Model::setEventDispatcher($dispatcher);
        try {
            app(TimesheetPayrollAdjustmentService::class)->process($row, $this->reviewer);
            $this->fail('A non3572 failure must propagate.');
        } catch (QueryException $caught) {
            $this->assertSame(1205, $caught->errorInfo[1]);
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($before, $this->state());
    }

    public function test_committed_receipt_is_withheld_from_another_authorized_requester(): void
    {
        $row = $this->amendment();
        $other = $this->actor(['timesheets.approve']);
        $this->commitFixtures();
        $this->actingAs($this->reviewer)->post($this->url($row))->assertRedirect()->assertSessionHas('timesheet_payroll_adjustment_result');
        $before = $this->state();
        $this->actingAs($other)->get('/operations/timesheets/payroll-adjustments')->assertInertia(fn ($page) => $page->where('flash.timesheet_payroll_adjustment_result', null));
        $this->assertSame($before, $this->state());
    }

    public function test_two_actual_writers_serialize_one_marker_and_one_processing_audit(): void
    {
        $row = $this->amendment();
        $this->commitFixtures();
        $protected = $this->protectedState();
        $first = $this->child($row, 'marker_saved');
        $this->waitReady($first);
        $second = $this->child($row, 'before_mutex');
        $secondReady = $this->waitReady($second);
        file_put_contents($second[1].'-release', 'release');
        $deadline = microtime(true) + 10;
        $waiting = false;
        while ($second[0]->isRunning() && microtime(true) < $deadline) {
            foreach (DB::select('SHOW PROCESSLIST') as $native) {
                if ((int) $native->Id === $secondReady['connection_id']
                    && str_contains((string) $native->Info, 'hr_payroll_run_mutexes')
                    && str_contains(strtolower((string) $native->Info), 'for update')) {
                    $waiting = true;
                    break 2;
                }
            }
            usleep(10000);
        }
        $this->assertTrue($waiting, 'The second owned writer must actually wait on the native application mutex.');
        file_put_contents($first[1].'-release', 'release');
        $first[0]->wait();
        $second[0]->wait();
        $this->assertTrue($first[0]->isSuccessful(), $first[0]->getErrorOutput());
        $this->assertTrue($second[0]->isSuccessful(), $second[0]->getErrorOutput());
        $created = json_decode($first[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $replayed = json_decode($second[0]->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($created['changed']);
        $this->assertFalse($replayed['changed']);
        $this->assertSame($created['applied_at'], $replayed['applied_at']);
        $this->assertSame(0, $created['transaction_level']);
        $this->assertSame(0, $replayed['transaction_level']);
        $this->assertSame(1, AuditLog::where('action', 'timesheet.amendment.payroll_processed')->count());
        $this->assertSame($protected, $this->protectedState());
        $this->assertSame($created['applied_at'], $row->fresh()->applied_at->toISOString());
    }

    private function child(TimesheetAmendment $row, string $phase): array
    {
        $prefix = base_path('test-results/payroll-adjustment-'.Str::uuid());
        $process = TimesheetPayrollAdjustmentProcess::make(['amendment_id' => $row->id, 'actor_id' => $this->reviewer->id, 'phase' => $phase], $prefix);
        $this->children[] = $process;
        $this->prefixes[] = $prefix;
        $process->start();

        return [$process, $prefix];
    }

    private function waitReady(array $child): array
    {
        [$process, $prefix] = $child;
        $deadline = microtime(true) + 35;
        while (! is_file($prefix.'-ready.json') && $process->isRunning() && microtime(true) < $deadline) {
            $process->checkTimeout();
            usleep(10000);
        }
        $this->assertFileExists($prefix.'-ready.json', $process->getErrorOutput());
        $value = json_decode(file_get_contents($prefix.'-ready.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $value['pid']);
        $this->assertGreaterThan(0, $value['transaction_level']);

        return $value;
    }

    private function actor(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'payroll-marker-'.Str::uuid(), 'label' => 'Payroll marker fixture', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'timesheets', 'module' => 'Operations'])->id));
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function grant(User $actor, string $key): void
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'timesheets', 'module' => 'Operations']);
        $actor->roles()->sole()->permissions()->syncWithoutDetaching($permission->id);
    }

    private function amendment(bool $linked = false, array $values = []): TimesheetAmendment
    {
        $shift = $linked ? Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $this->client->id,
            'site_id' => $this->site->id, 'service_context_id' => $this->client->service_context_id,
            'starts_at' => '2026-10-06 20:00:00', 'ends_at' => '2026-10-07 00:00:00', 'status' => 'scheduled', 'created_by' => $this->worker->id]) : null;
        $timesheet = Timesheet::factory()->create(array_replace(['user_id' => $this->worker->id, 'client_id' => $this->client->id,
            'shift_id' => $shift?->id, 'site_id' => $this->site->id, 'shift_site_id' => $this->site->id, 'status' => 'draft',
            'work_date' => '2026-10-07', 'starts_at' => '2026-10-06 20:00:00', 'ends_at' => '2026-10-07 00:00:00',
            'staff_name_snapshot' => $this->worker->name, 'client_name_snapshot' => 'Recorded client',
            'shift_site_name_snapshot' => $this->site->name, 'created_by' => $this->worker->id], $values));
        DB::table('timesheets')->where('id', $timesheet->id)->update(['status' => 'approved', 'approved_by' => $this->reviewer->id,
            'approved_at' => now()->subDay(), 'payroll_reference' => 'Recorded external run', 'exported_to_payroll_at' => now()->subDay()]);

        return TimesheetAmendment::create(['timesheet_id' => $timesheet->id, 'status' => 'approved', 'reason' => 'Private pay correction',
            'original_values' => ['pay_rate' => '25.00', 'notes' => 'Original private detail'], 'proposed_values' => ['pay_rate' => '26.00', 'notes' => 'Proposed private detail'],
            'requested_by' => $this->worker->id, 'requested_at' => now()->subDays(2), 'reviewed_by' => $this->reviewer->id,
            'reviewed_at' => now()->subDay(), 'payroll_adjustment_required' => true, 'applied_at' => null]);
    }

    private function url(TimesheetAmendment $row): string
    {
        return '/operations/timesheets/amendments/'.$row->id.'/mark-processed';
    }

    private function protectedState(?Connection $connection = null): array
    {
        return $this->tableState(['timesheets', 'timesheet_client_allocations', 'hr_time_entries', 'hr_payroll_source_uses',
            'hr_payroll_runs', 'hr_payroll_run_items', 'billing_entries', 'notifications'], $connection);
    }

    private function state(?Connection $connection = null): array
    {
        return $this->protectedState($connection) + $this->tableState(['timesheet_amendments', 'audit_logs', 'users', 'role_user', 'role_permission', 'hr_employee_profiles', 'sites', 'clients', 'shifts'], $connection);
    }

    private function tableState(array $tables, ?Connection $connection): array
    {
        $connection ??= DB::connection();

        return collect($tables)->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy($table === 'role_user' ? 'role_id' : ($table === 'role_permission' ? 'role_id' : 'id'))
            ->when($table === 'role_user', fn ($query) => $query->orderBy('user_id'))
            ->when($table === 'role_permission', fn ($query) => $query->orderBy('permission_id'))->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function writer(): Connection
    {
        config(['database.connections.payroll_adjustment_writer' => DB::connection()->getConfig()]);
        DB::purge('payroll_adjustment_writer');
        $writer = DB::connection('payroll_adjustment_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
