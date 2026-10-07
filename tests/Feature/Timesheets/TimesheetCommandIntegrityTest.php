<?php

namespace Tests\Feature\Timesheets;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Shifts\Timesheets\TimesheetAllocationService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Domain\Shifts\Timesheets\TimesheetCommandReceipt;
use App\Domain\Shifts\Timesheets\TimesheetCreationService;
use App\Domain\Shifts\Timesheets\TimesheetWorkflowResult;
use App\Jobs\RefreshWorkforceEligibility;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetClientAllocation;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
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
use Tests\TestCase;

class TimesheetCommandIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $reviewer;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 04:00:00', 'UTC'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['site_id' => null, 'is_active' => true, 'type' => 'residential']);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active']);
        $this->worker = $this->actor(['timesheets.viewAssigned', 'timesheets.create', 'timesheets.update', 'timesheets.submit']);
        $this->reviewer = $this->actor(['timesheets.viewAny', 'timesheets.approve', 'timesheets.manageAny']);
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('timesheet_command_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function actions(): array
    {
        return array_combine(['create', 'update', 'submit', 'resubmit', 'approve', 'reject', 'return'],
            array_map(fn ($action) => [$action], ['create', 'update', 'submit', 'resubmit', 'approve', 'reject', 'return']));
    }

    #[DataProvider('actions')]
    public function test_actual_root_receipt_has_exact_persisted_values_hash_and_actor(string $action): void
    {
        $row = $action === 'create' ? null : $this->timesheet($this->statusFor($action));
        $this->commitFixtures();
        $this->withSession(['timesheet_result' => ['action' => 'old', 'actor_id' => -1]]);
        $response = $this->requestCommand($action, $row);
        $action === 'create' ? $response->assertOk() : $response->assertRedirect();
        $receipt = $action === 'create' ? $response->json('timesheet_result') : session('timesheet_result');
        $this->assertIsArray($receipt);
        $persisted = Timesheet::findOrFail($receipt['timesheet_id']);
        $this->assertSame($this->actorFor($action)->id, $receipt['actor_id']);
        $this->assertSame($this->worker->id, $receipt['user_id']);
        $this->assertSame($action, $receipt['action']);
        $this->assertTrue($receipt['changed']);
        $this->assertSame('saved', $receipt['outcome']);
        $this->assertSame($action === 'update' ? 'draft' : ($action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : ($action === 'return' ? 'returned' : 'submitted'))), $receipt['status']);
        $this->assertSame($persisted->status, $receipt['status']);
        $this->assertSame($this->expectedHash($action, $persisted), $receipt['values_hash']);
        $this->assertSame('2026-10-07', $receipt['work_date']);
        $this->assertSame('2026-10-06T20:00:00.000000Z', $persisted->starts_at->toISOString());
        $this->assertSame(0, $receipt['break_minutes']);
        $this->assertSame($this->site->id, $receipt['effective_site_id']);
        $this->assertArrayNotHasKey('notes', $receipt);
        $this->assertArrayNotHasKey('reason', $receipt);
        $this->assertArrayNotHasKey('tasks', $receipt);
        $this->assertArrayNotHasKey('pay_rate', $receipt);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        if ($action === 'create') {
            $this->assertTrue($receipt['submit_requested']);
        }
        if (in_array($action, ['create', 'submit', 'resubmit'], true)) {
            $this->assertSame($this->worker->id, $receipt['submitted_by']);
        }
        if ($action === 'approve') {
            $this->assertSame($this->reviewer->id, $receipt['approved_by']);
            $this->assertNotNull($persisted->hr_time_entry_id);
            $this->assertDatabaseHas('billing_entries', ['timesheet_id' => $persisted->id, 'client_id' => $this->client->id]);
            $this->assertNotSame('paid', $persisted->status);
        }
    }

    public static function nestedActions(): array
    {
        $rows = [];
        foreach (array_keys(self::actions()) as $action) {
            foreach ([false, true] as $commit) {
                $rows[$action.($commit ? ' commit' : ' rollback')] = [$action, $commit];
            }
        }

        return $rows;
    }

    #[DataProvider('nestedActions')]
    public function test_nested_command_never_publishes_a_committed_receipt(string $action, bool $commit): void
    {
        $row = $action === 'create' ? null : $this->timesheet($this->statusFor($action));
        $this->commitFixtures();
        $before = $this->state();
        $oldManager = app('db.transactions');
        $manager = new DatabaseTransactionsManager;
        app()->instance('db.transactions', $manager);
        DB::connection()->setTransactionManager($manager);
        $notices = [];
        $this->mock(NotificationService::class, function ($mock) use (&$notices) {
            $mock->shouldReceive('notifyCrud')->andReturnUsing(function ($actor, $event) use (&$notices) {
                $notices[] = $event;
            });
        });
        try {
            DB::beginTransaction();
            $response = $this->requestCommand($action, $row);
            $action === 'create' ? $response->assertOk()->assertJsonMissingPath('timesheet_result') : $response->assertRedirect()->assertSessionMissing('timesheet_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame([], $notices);
            $commit ? DB::commit() : DB::rollBack();
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertFalse(session()->has('timesheet_result'));
            if (! $commit) {
                $this->assertSame($before, $this->state());
                $this->assertSame([], $notices);
            } else {
                $this->assertSame($action === 'create' ? 2 : 1, count($notices));
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            app()->instance('db.transactions', $oldManager);
            DB::connection()->setTransactionManager($oldManager);
        }
    }

    public static function drift(): array
    {
        return [
            'create approval' => ['create', 'actor_approval'], 'create grant' => ['create', 'grant'],
            'create profile' => ['create', 'profile'], 'create Site' => ['create', 'site'],
            'create Client Site' => ['create', 'client_site'],
            'writer approval' => ['submit', 'actor_approval'], 'reviewer approval' => ['approve', 'actor_approval'],
            'writer grant' => ['submit', 'grant'], 'reviewer grant' => ['approve', 'grant'],
            'writer profile' => ['update', 'profile'], 'reviewer membership' => ['approve', 'membership'],
            'current Site' => ['approve', 'site'], 'archived Site' => ['submit', 'archived'],
            'Client Site' => ['approve', 'client_site'], 'deleted Client' => ['submit', 'deleted_client'],
            'recipient portal' => ['approve', 'portal'], 'recipient membership' => ['approve', 'recipient_membership'],
            'bound Shift owner' => ['approve', 'shift_owner'], 'bound Timesheet owner' => ['submit', 'row_owner'],
        ];
    }

    #[DataProvider('drift')]
    public function test_primed_rr_current_authority_denial_preserves_all_existing_data(string $action, string $change): void
    {
        $other = $this->actor(['timesheets.viewAssigned']);
        $foreignSite = Site::factory()->create(['is_active' => true]);
        $row = $this->timesheet($this->statusFor($action), true);
        $actor = $this->actorFor($action);
        $this->assertTrue($actor->isApproved());
        $this->assertTrue($actor->canDo($action === 'approve' ? 'timesheets.approve' : ($action === 'update' ? 'timesheets.update' : 'timesheets.submit')));
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $oldActor = User::findOrFail($actor->id);
        $oldClient = Client::findOrFail($this->client->id);
        DB::table('hr_employee_profiles')->get();
        DB::table('sites')->get();
        DB::table('shifts')->get();
        DB::table('timesheets')->get();
        if ($change === 'actor_approval') {
            $writer->table('users')->where('id', $actor->id)->update(['approved_at' => null]);
        } elseif ($change === 'grant') {
            $writer->table('role_permission')->where('role_id', $actor->roles()->first()->id)->delete();
        } elseif ($change === 'profile') {
            $writer->table('hr_employee_profiles')->where('user_id', $actor->id)->update(['is_active' => false]);
        } elseif ($change === 'membership') {
            $writer->table('hr_employee_profiles')->where('user_id', $actor->id)->update(['primary_site_id' => $foreignSite->id, 'secondary_site_ids' => json_encode([])]);
        } elseif ($change === 'site') {
            $writer->table('sites')->where('id', $this->site->id)->update(['is_active' => false]);
        } elseif ($change === 'archived') {
            $writer->table('sites')->where('id', $this->site->id)->update(['archived' => true]);
        } elseif ($change === 'client_site') {
            $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $foreignSite->id]);
        } elseif ($change === 'deleted_client') {
            $writer->table('clients')->where('id', $this->client->id)->update(['deleted_at' => now()]);
        } elseif ($change === 'portal') {
            $writer->table('users')->where('id', $this->worker->id)->update(['role' => 'client']);
        } elseif ($change === 'recipient_membership') {
            $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['primary_site_id' => $foreignSite->id, 'secondary_site_ids' => json_encode([])]);
        } elseif ($change === 'shift_owner') {
            $writer->table('shifts')->where('id', $row->shift_id)->update(['user_id' => $other->id]);
        } else {
            $writer->table('timesheets')->where('id', $row->id)->update(['user_id' => $other->id]);
        }
        if ($change === 'grant') {
            $this->assertSame(0, $writer->table('role_permission')->where('role_id', $actor->roles()->first()->id)->count());
        }
        $this->assertTrue($oldActor->isApproved());
        $this->assertSame($this->site->id, Client::findOrFail($oldClient->id)->site_id);
        $before = $this->state($writer);
        try {
            $service = app(TimesheetApprovalService::class);
            match ($action) {
                'create' => app(TimesheetCreationService::class)->create($actor, $this->body()),
                'approve' => $service->approve($row, $actor, 'Must not save'), 'update' => $service->updateEditable($row, $actor, ['notes' => 'Must not save']), default => $service->submit($row, $actor)
            };
            $this->fail('Current revoked evidence must deny the command.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_create_and_optional_submit_denial_rolls_back_the_entire_new_draft(): void
    {
        $this->worker->roles()->first()->permissions()->detach(Permission::where('key', 'timesheets.submit')->value('id'));
        $this->commitFixtures();
        $before = $this->state();
        $this->actingAs($this->worker)->postJson('/operations/timesheets', $this->body(['submit' => true]))->assertForbidden()->assertJsonMissingPath('timesheet_result');
        $this->assertSame($before, $this->state());
        Notification::assertNothingSent();
    }

    public function test_duplicate_creation_keeps_original_row_and_has_no_success_receipt(): void
    {
        $row = $this->timesheet('draft', true);
        $this->commitFixtures();
        $before = $this->state();
        $this->actingAs($this->worker)->postJson('/operations/timesheets', $this->body(['mode' => 'shift', 'shift_id' => $row->shift_id, 'submit' => false]))
            ->assertUnprocessable()->assertJsonValidationErrors('shift_id')->assertJsonMissingPath('timesheet_result');
        $this->assertSame($before, $this->state());
    }

    public function test_manual_siteless_and_manager_open_shift_defaults_are_preserved(): void
    {
        $open = Shift::factory()->create(['user_id' => null, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'status' => 'draft', 'created_by' => $this->worker->id]);
        $this->reviewer->roles()->first()->permissions()->attach(Permission::where('key', 'timesheets.create')->value('id'));
        $this->commitFixtures();
        $response = $this->actingAs($this->worker)->postJson('/operations/timesheets', $this->body(['site_id' => null, 'client_id' => null, 'submit' => false]))->assertOk();
        $manual = Timesheet::findOrFail($response->json('timesheet_id'));
        $this->assertNull($manual->shift_site_id);
        $this->assertNull($manual->site_id);
        $this->assertSame('draft', $manual->status);
        $response = $this->actingAs($this->reviewer)->postJson('/operations/timesheets', $this->body(['mode' => 'shift', 'shift_id' => $open->id, 'submit' => false]))->assertOk();
        $copy = Timesheet::findOrFail($response->json('timesheet_id'));
        $this->assertSame($this->reviewer->id, $copy->user_id);
        $this->assertNull($open->fresh()->user_id);
        $this->assertSame('draft', $open->fresh()->status);
        $this->assertSame($this->reviewer->id, $response->json('timesheet_result.user_id'));
    }

    public function test_already_approved_result_does_not_certify_a_new_reason_or_repeat_effects(): void
    {
        $row = $this->timesheet('submitted');
        $this->commitFixtures();
        $this->requestCommand('approve', $row)->assertRedirect();
        $before = $this->state();
        $response = $this->actingAs($this->reviewer)->post('/operations/timesheets/'.$row->id.'/approve', ['decision_notes' => 'Unapplied late reason'])->assertRedirect();
        $receipt = session('timesheet_result');
        $this->assertFalse($receipt['changed']);
        $this->assertSame('already_in_state', $receipt['outcome']);
        $this->assertSame(hash('sha256', json_encode(['timesheet_id' => $row->id, 'action' => 'approve'], JSON_THROW_ON_ERROR)), $receipt['values_hash']);
        $this->assertSame($before, $this->state());
        $this->assertSame('Timesheet already approved.', session('success'));
    }

    public static function vetoes(): array
    {
        return ['veto create' => ['create', false], 'alter create' => ['create', true], 'veto edit' => ['update', false], 'alter edit' => ['update', true]];
    }

    #[DataProvider('vetoes')]
    public function test_actual_model_veto_or_changed_persisted_intent_rolls_back_without_receipt(string $action, bool $alter): void
    {
        $row = $action === 'create' ? null : $this->timesheet('draft');
        $this->commitFixtures();
        $before = $this->state();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.saving: '.Timesheet::class, function ($model) use ($alter) {
            if ($alter) {
                $model->notes = 'Different unsent intent';

                return null;
            }

            return false;
        });
        Model::setEventDispatcher($dispatcher);
        try {
            $this->requestCommand($action, $row)->assertStatus(409);
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($before, $this->state());
        $this->assertFalse(session()->has('timesheet_result'));
    }

    public function test_real_held_recipient_role_is_recoverable_nowait_and_has_no_partial_effects(): void
    {
        $row = $this->timesheet('submitted', true);
        $this->commitFixtures();
        $before = $this->state();
        $holder = $this->writer();
        $holder->beginTransaction();
        $holder->table('roles')->where('id', $this->worker->roles()->first()->id)->lockForUpdate()->first();
        try {
            app(TimesheetApprovalService::class)->approve($row, $this->reviewer, 'Must not save');
            $this->fail('Held recipient role must not silently authorise approval.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Current timesheet evidence is busy. Refresh and try again.'], $exception->errors()['timesheet']);
        } finally {
            $holder->rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public function test_saved_allocation_client_drift_is_currently_denied_under_primed_rr(): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $row = $this->timesheet('draft', true);
        TimesheetClientAllocation::create(['timesheet_id' => $row->id, 'client_id' => $other->id, 'hours' => 4, 'allocation_method' => 'single', 'sort_order' => 0]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $this->assertSame('active', Client::findOrFail($other->id)->status);
        $writer->table('clients')->where('id', $other->id)->update(['status' => 'archived']);
        $before = $this->state($writer);
        try {
            app(TimesheetApprovalService::class)->submit($row, $this->worker);
            $this->fail('Archived allocated person must not pass a stale roster.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('client_allocations', $exception->errors());
        } finally {
            DB::rollBack();
        }
        $this->assertSame($before, $this->state());
    }

    public static function allocationVetoes(): array
    {
        return ['veto allocation save' => [false], 'alter allocation save' => [true]];
    }

    #[DataProvider('allocationVetoes')]
    public function test_my_day_allocation_save_veto_or_changed_intent_rolls_back_the_whole_split(bool $alter): void
    {
        $other = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $row = $this->timesheet('draft', true);
        foreach ([$this->client, $other] as $index => $client) {
            TimesheetClientAllocation::create(['timesheet_id' => $row->id, 'client_id' => $client->id,
                'hours' => 2, 'allocation_method' => 'equal_split', 'sort_order' => $index]);
        }
        $revision = app(TimesheetAllocationService::class)->revision($row);
        $this->commitFixtures();
        $before = $this->state();
        $original = Model::getEventDispatcher();
        $dispatcher = clone $original;
        $dispatcher->listen('eloquent.saving: '.TimesheetClientAllocation::class, function ($allocation) use ($alter, $row) {
            if ((int) $allocation->timesheet_id !== (int) $row->id) {
                return null;
            }
            if ($alter) {
                $allocation->hours = 3;

                return null;
            }

            return false;
        });
        Model::setEventDispatcher($dispatcher);
        try {
            $this->actingAs($this->worker)->putJson('/my-day/timesheets/'.$row->id.'/allocations', [
                'expected_revision' => $revision,
                'client_allocations' => [['client_id' => $this->client->id, 'hours' => 4, 'allocation_method' => 'single']],
            ])->assertStatus(409)->assertJsonMissingPath('saved');
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($before, $this->state());
        $this->assertSame('draft', $row->fresh()->status);
        $this->assertFalse(session()->has('timesheet_result'));
    }

    public static function splitDrift(): array
    {
        return ['changed current split' => [false], 'cleared current split' => [true]];
    }

    #[DataProvider('splitDrift')]
    public function test_approval_bills_only_the_current_locked_split_after_an_independent_commit(bool $clear): void
    {
        $b = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $c = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $row = $this->timesheet('submitted', true);
        $oldClient = $clear ? $b : $this->client;
        TimesheetClientAllocation::create(['timesheet_id' => $row->id, 'client_id' => $oldClient->id,
            'hours' => 4, 'allocation_method' => 'single', 'sort_order' => 0]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $this->assertSame($oldClient->id, $row->effectiveClientAllocations()->first()['client_id']);
        $writer->transaction(function () use ($writer, $row, $b, $c, $clear): void {
            $writer->table('timesheet_client_allocations')->where('timesheet_id', $row->id)->delete();
            if (! $clear) {
                $writer->table('timesheet_client_allocations')->insert([
                    ['timesheet_id' => $row->id, 'client_id' => $b->id, 'hours' => '1.25', 'allocation_method' => 'manual', 'sort_order' => 0],
                    ['timesheet_id' => $row->id, 'client_id' => $c->id, 'hours' => '2.75', 'allocation_method' => 'manual', 'sort_order' => 1],
                ]);
            }
        });
        $this->assertSame(1, $row->clientAllocations()->count());
        $this->assertSame($oldClient->id, $row->clientAllocations()->first()->client_id);
        $result = app(TimesheetApprovalService::class)->approve($row, $this->reviewer, 'Current split');
        $this->assertTrue($result->timesheet->relationLoaded('clientAllocations'));
        $this->assertCount($clear ? 0 : 2, $result->timesheet->clientAllocations);
        DB::commit();
        $expected = $clear ? [$this->client->id => '4.00'] : [$b->id => '1.25', $c->id => '2.75'];
        ksort($expected);
        $this->assertSame($expected, DB::table('billing_entries')->where('timesheet_id', $row->id)->orderBy('client_id')->pluck('hours', 'client_id')->all());
        $this->assertSame('approved', $row->fresh()->status);
        $this->assertNotNull($result->timesheet->hr_time_entry_id);
        $this->assertSame($clear ? 0 : 2, $row->clientAllocations()->count());
    }

    public function test_current_source_names_are_used_by_approval_hr_and_billing_after_primed_rr(): void
    {
        $row = $this->timesheet('submitted', true);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $old = Client::findOrFail($this->client->id)->first_name;
        Site::findOrFail($this->site->id);
        User::findOrFail($this->worker->id);
        $writer->table('clients')->where('id', $this->client->id)->update(['first_name' => 'Current proof', 'last_name' => 'Person']);
        $writer->table('sites')->where('id', $this->site->id)->update(['name' => 'Current proof Site']);
        $writer->table('users')->where('id', $this->worker->id)->update(['name' => 'Current proof Worker']);
        $this->assertSame($old, Client::findOrFail($this->client->id)->first_name);
        $result = app(TimesheetApprovalService::class)->approve($row, $this->reviewer, 'Actual current source');
        $this->assertSame('Current proof Person', $result->timesheet->client_name_snapshot);
        $this->assertSame('Current proof Worker', $result->timesheet->staff_name_snapshot);
        $this->assertSame('Current proof Site', $result->timesheet->shift_site_name_snapshot);
        DB::commit();
        $this->assertDatabaseHas('billing_entries', ['timesheet_id' => $row->id, 'client_name_snapshot' => 'Current proof Person', 'site_name_snapshot' => 'Current proof Site']);
        $this->assertDatabaseHas('hr_time_entries', ['id' => $result->timesheet->hr_time_entry_id, 'site_id' => $this->site->id]);
    }

    public function test_post_commit_projection_and_logger_faults_do_not_imply_creation_failed(): void
    {
        $this->commitFixtures();
        $this->partialMock(TimesheetCommandReceipt::class, fn ($mock) => $mock->shouldAllowMockingProtectedMethods()->shouldReceive('valuesHash')->andThrow(new \RuntimeException('Projection unavailable')));
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('Logger unavailable'));
        $response = $this->requestCommand('create')->assertOk()->assertJsonMissingPath('timesheet_result');
        $this->assertDatabaseHas('timesheets', ['id' => $response->json('timesheet_id'), 'status' => 'submitted']);
    }

    public function test_post_commit_notice_and_logger_faults_preserve_committed_receipt(): void
    {
        $this->commitFixtures();
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('notifyCrud')->twice()->andThrow(new \RuntimeException('Notice unavailable')));
        Log::shouldReceive('warning')->andThrow(new \RuntimeException('Logger unavailable'));
        $this->requestCommand('create')->assertOk()->assertJsonPath('timesheet_result.status', 'submitted');
    }

    public function test_exact_action_flags_do_not_treat_manage_as_update_or_submit_permission(): void
    {
        $row = $this->timesheet('draft');
        $this->worker->roles()->first()->permissions()->sync(Permission::whereIn('key', ['timesheets.viewAssigned', 'timesheets.submit', 'timesheets.manageAny'])->pluck('id'));
        $response = $this->actingAs($this->worker->fresh())->get('/operations/timesheets');
        $response->assertInertia(fn ($page) => $page->where('canSubmit', true)->where('timesheets.data.0.can_submit', true)
            ->where('timesheets.data.0.can_update', false)->where('timesheets.data.0.can_resubmit', false));
    }

    public function test_actual_pdo_transaction_without_a_framework_root_cannot_certify_a_receipt(): void
    {
        $row = $this->timesheet('submitted');
        $this->commitFixtures();
        $pdo = DB::connection()->getPdo();
        $pdo->beginTransaction();
        try {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertTrue($pdo->inTransaction());
            $result = new TimesheetWorkflowResult($row, true, $this->worker->id);
            $this->assertNull(app(TimesheetCommandReceipt::class)->committed(true, 'submit', $result));
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_reconciliation_failure_rolls_back_creation_and_its_optional_submission(): void
    {
        $row = $this->timesheet('draft', true);
        $shift = $row->shift;
        $row->delete();
        $this->assertSame(1, $shift->attendanceSessions()->where('status', 'closed')->count());
        $this->commitFixtures();
        $before = $this->state();
        $this->actingAs($this->worker)->postJson('/operations/timesheets', $this->body(['mode' => 'shift', 'shift_id' => $shift->id,
            'starts_at' => '2026-10-06T21:00:00Z']))->assertUnprocessable()->assertJsonValidationErrors('timesheet')->assertJsonMissingPath('timesheet_result');
        $this->assertSame($before, $this->state());
        $this->assertSame('completed', $shift->fresh()->status);
    }

    public function test_committed_receipt_is_withheld_from_a_different_requester_without_changing_the_record(): void
    {
        $row = $this->timesheet('draft');
        $this->commitFixtures();
        $this->requestCommand('update', $row)->assertRedirect()->assertSessionHas('timesheet_result');
        $before = $this->state();
        $this->actingAs($this->reviewer)->get('/operations/timesheets')->assertInertia(fn ($page) => $page->where('flash.timesheet_result', null));
        $this->assertSame($before, $this->state());
    }

    private function actor(array $keys): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::create(['name' => 'time-command-'.Str::uuid(), 'label' => 'Time command fixture', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'timesheets', 'module' => 'Operations'])->id));
        $user->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => '2026-01-01', 'end_date' => null, 'is_active' => true, 'hourly_rate' => 25,
            'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function body(array $overrides = []): array
    {
        return array_replace(['mode' => 'manual', 'activity_type' => 'meeting', 'activity_items' => ['Recorded meeting'],
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'work_date' => '2026-10-07',
            'starts_at' => '2026-10-06T20:00:00Z', 'ends_at' => '2026-10-07T00:00:00Z', 'break_minutes' => 0,
            'mileage_km' => 0, 'sleepover' => false, 'on_call' => false, 'allowance_notes' => '', 'public_holiday' => false,
            'notes' => " \u{00A0}Recorded / Unicode ā\u{2028}line\u{00A0} ", 'is_residential_billable' => false, 'submit' => true], $overrides);
    }

    private function timesheet(string $status, bool $linked = false): Timesheet
    {
        $body = $this->body();
        $shift = $linked ? Shift::factory()->create(['user_id' => $this->worker->id, 'client_id' => $this->client->id,
            'site_id' => $this->site->id, 'service_context_id' => $this->client->service_context_id,
            'starts_at' => '2026-10-06 20:00:00', 'ends_at' => '2026-10-07 00:00:00', 'actual_starts_at' => '2026-10-06 20:00:00',
            'actual_ends_at' => '2026-10-07 00:00:00', 'expected_break_minutes' => 0, 'status' => 'completed', 'created_by' => $this->worker->id]) : null;

        if ($shift) {
            HrAttendanceSession::create(['user_id' => $this->worker->id, 'shift_id' => $shift->id,
                'site_id' => $this->site->id, 'clock_in_at' => $shift->starts_at, 'clock_out_at' => $shift->ends_at,
                'break_minutes' => 0, 'status' => 'closed', 'source' => 'manual',
                'created_by' => $this->worker->id, 'closed_by' => $this->worker->id]);
        }

        return Timesheet::create(['user_id' => $this->worker->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'shift_id' => $shift?->id, 'shift_site_id' => $this->site->id, 'shift_service_context_id' => $this->client->service_context_id,
            'work_date' => $body['work_date'], 'starts_at' => '2026-10-06 20:00:00', 'ends_at' => '2026-10-07 00:00:00', 'break_minutes' => 0,
            'activity_type' => $shift ? null : 'meeting', 'activity_items' => $shift ? null : ['Recorded meeting'], 'notes' => 'Original notes',
            'status' => $status, 'created_by' => $this->worker->id, 'submitted_by' => $status === 'submitted' ? $this->worker->id : null,
            'submitted_at' => $status === 'submitted' ? now() : null, 'staff_name_snapshot' => $this->worker->name,
            'client_name_snapshot' => trim($this->client->first_name.' '.$this->client->last_name), 'shift_site_name_snapshot' => $this->site->name,
            'service_context_name_snapshot' => 'Residential', 'shift_type_snapshot' => 'standard', 'coverage_roles_snapshot' => []]);
    }

    private function requestCommand(string $action, ?Timesheet $row = null)
    {
        $this->actingAs($this->actorFor($action));
        if ($action === 'create') {
            return $this->postJson('/operations/timesheets', $this->body());
        }
        $url = '/operations/timesheets/'.$row->id;
        if ($action === 'update') {
            return $this->put($url, $this->body());
        }
        if ($action === 'resubmit') {
            return $this->post($url.'/resubmit', $this->body());
        }
        if ($action === 'return') {
            return $this->post($url.'/return', ['returned_notes' => '  Reviewed reason  ']);
        }

        return $this->post($url.'/'.$action, $action === 'submit' ? [] : ['decision_notes' => '  Reviewed reason  ']);
    }

    private function actorFor(string $action): User
    {
        return in_array($action, ['approve', 'reject', 'return'], true) ? $this->reviewer : $this->worker;
    }

    private function statusFor(string $action): string
    {
        return in_array($action, ['approve', 'reject', 'return'], true) ? 'submitted' : ($action === 'resubmit' ? 'returned' : 'draft');
    }

    private function expectedHash(string $action, Timesheet $row): string
    {
        if (in_array($action, ['create', 'update', 'resubmit'], true)) {
            $manual = ['client_id' => $this->client->id];
            if ($action === 'create') {
                $manual += ['site_id' => $this->site->id, 'activity_type' => 'meeting'];
            }
            $manual += ['sleepover' => false, 'on_call' => false];
            $values = ['work_date' => '2026-10-07', 'starts_at' => '2026-10-06T20:00:00.000Z', 'ends_at' => '2026-10-07T00:00:00.000Z',
                'break_minutes' => 0, 'mileage_km' => '0.00', 'allowance_notes' => null, 'public_holiday' => false,
                'notes' => Str::trim($this->body()['notes']), 'is_residential_billable' => false, 'manual' => $manual];
            if ($action === 'create') {
                $values['activity_items'] = ['Recorded meeting'];
            }
        } else {
            $values = ['timesheet_id' => $row->id, 'action' => $action];
            if ($action !== 'submit') {
                $values['reason'] = 'Reviewed reason';
            }
        }

        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function state(?Connection $connection = null): array
    {
        $connection ??= DB::connection();

        return collect(['timesheets', 'timesheet_client_allocations', 'hr_time_entries', 'hr_payroll_source_uses', 'billing_entries', 'audit_logs', 'notifications'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy($table === 'notifications' ? 'id' : 'id')->get()->map(fn ($row) => (array) $row)->all()])->all();
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
        Queue::fake([RefreshWorkforceEligibility::class]);
        Notification::fake();
    }

    private function writer(): Connection
    {
        config(['database.connections.timesheet_command_writer' => DB::connection()->getConfig()]);
        DB::purge('timesheet_command_writer');
        $writer = DB::connection('timesheet_command_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
