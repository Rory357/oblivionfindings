<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\StaffAvailabilityController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Actual physical commits and independent current-evidence races; no writer fakes. */
class WorkforceAvailabilityCommandReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $worker;

    private User $manager;

    private bool $fixturesCommitted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        Carbon::setTestNow(Carbon::parse('2026-10-06 05:00:00', 'UTC'));
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker = $this->staff(['staff.availability.updateSelf']);
        $this->manager = $this->staff(['staff.availability.updateAny']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturesCommitted && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    #[DataProvider('validWindows')]
    public function test_real_create_receipt_and_standalone_snapshot_describe_the_actual_minute_window(array $window): void
    {
        $this->commitFixtures();
        $response = $this->actingAs($this->worker)->from('/weekly-pattern')
            ->post(route('staff.availability.store', $this->worker), $window)
            ->assertRedirect('/weekly-pattern')->assertSessionHas('success', 'Availability added.')->assertSessionHasNoErrors();
        $slot = StaffAvailability::where('user_id', $this->worker->id)->sole();
        $receipt = $this->assertReceipt($response, 'create', $slot);
        $this->assertSame($window['starts_at'].':00', $slot->starts_at);
        $this->assertSame($window['ends_at'].':00', $slot->ends_at);
        $this->get(route('staff.availability.index', $this->worker))->assertInertia(fn (Assert $page) => $page
            ->where('user.id', $this->worker->id)->where('canManage', true)->where('workerTimezone', 'Pacific/Auckland')
            ->where('availability', [[
                'id' => $slot->id, 'day_of_week' => $window['day_of_week'], 'starts_at' => $window['starts_at'],
                'ends_at' => $window['ends_at'], 'ends_next_day' => $window['ends_next_day'],
            ]])->where('flash.staff_availability_result', $receipt));
        $this->get(route('staff.availability.index', $this->worker))
            ->assertInertia(fn (Assert $page) => $page->where('flash.staff_availability_result', null));
    }

    public static function validWindows(): array
    {
        return [
            'same day' => [['day_of_week' => 2, 'starts_at' => '09:17', 'ends_at' => '17:43', 'ends_next_day' => false]],
            'overnight' => [['day_of_week' => 6, 'starts_at' => '21:17', 'ends_at' => '07:43', 'ends_next_day' => true]],
            'whole local day' => [['day_of_week' => 0, 'starts_at' => '09:17', 'ends_at' => '09:17', 'ends_next_day' => true]],
        ];
    }

    public function test_real_delete_receipt_matches_the_read_window_and_repeated_delete_does_not_reuse_it(): void
    {
        $slot = $this->slot()->fresh();
        $this->commitFixtures();
        $this->actingAs($this->worker)->get(route('staff.availability.index', $this->worker))
            ->assertInertia(fn (Assert $page) => $page->where('availability.0.starts_at', '21:17')
                ->where('availability.0.ends_at', '07:43')->where('availability.0.ends_next_day', true));
        $response = $this->from('/weekly-pattern')->delete(route('staff.availability.destroy', [$this->worker, $slot]))
            ->assertRedirect('/weekly-pattern')->assertSessionHas('success', 'Availability removed.');
        $this->assertReceipt($response, 'delete', $slot);
        $this->assertDatabaseMissing('staff_availabilities', ['id' => $slot->id]);
        // A GET consumes the one-time receipt before the bound missing-row denial.
        $this->get(route('staff.availability.index', $this->worker))
            ->assertInertia(fn (Assert $page) => $page->has('availability', 0)->where('flash.staff_availability_result.action', 'delete'));
        $this->delete(route('staff.availability.destroy', [$this->worker, $slot]))->assertNotFound()
            ->assertSessionMissing('staff_availability_result');
    }

    #[DataProvider('nestedCommands')]
    public function test_nested_success_never_claims_root_commit_and_preserves_outer_commit_or_rollback(string $action, bool $commit): void
    {
        $slot = $action === 'delete' ? $this->slot() : null;
        $this->commitFixtures();
        $before = $this->state();
        DB::beginTransaction();
        try {
            $response = $this->actingAs($this->worker)->withSession(['staff_availability_result' => ['action' => 'stale']])
                ->commandRequest($action, $this->worker, $slot);
            $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('staff_availability_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('staff_availability_result'));
            if ($commit) {
                $this->assertDatabaseCount('staff_availabilities', $action === 'create' ? 1 : 0);
            } else {
                $this->assertSame($before, $this->state());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function nestedCommands(): array
    {
        return ['create commit' => ['create', true], 'create rollback' => ['create', false],
            'delete commit' => ['delete', true], 'delete rollback' => ['delete', false]];
    }

    #[DataProvider('actions')]
    public function test_actual_model_failure_rolls_back_the_row_audit_and_clears_an_old_receipt(string $action): void
    {
        $slot = $action === 'delete' ? $this->slot() : null;
        $this->commitFixtures();
        $before = $this->state();
        $listener = 'eloquent.'.($action === 'create' ? 'created' : 'deleted').': '.StaffAvailability::class;
        Event::listen($listener, fn () => throw new RuntimeException('Controlled availability writer failure.'));
        $this->withoutExceptionHandling();
        try {
            try {
                $this->actingAs($this->worker)->withSession(['staff_availability_result' => ['action' => 'stale']])
                    ->commandRequest($action, $this->worker, $slot);
                $this->fail('The actual model failure must escape the command.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Controlled availability writer failure.', $exception->getMessage());
            }
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
        $this->assertNull(session('staff_availability_result'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    #[DataProvider('actions')]
    public function test_model_veto_preserves_existing_success_semantics_without_fabricating_a_mutation_receipt(string $action): void
    {
        $slot = $action === 'delete' ? $this->slot() : null;
        $this->commitFixtures();
        $before = $this->state();
        $listener = 'eloquent.'.($action === 'create' ? 'creating' : 'deleting').': '.StaffAvailability::class;
        Event::listen($listener, fn () => false);
        try {
            $this->actingAs($this->worker)->withSession(['staff_availability_result' => ['action' => 'stale']])
                ->commandRequest($action, $this->worker, $slot)->assertRedirect()
                ->assertSessionHas('success', $action === 'create' ? 'Availability added.' : 'Availability removed.')
                ->assertSessionMissing('staff_availability_result');
        } finally {
            Event::forget($listener);
        }
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('committedRevocations')]
    public function test_committed_account_grant_profile_and_site_changes_cannot_be_resurrected_from_rr(string $action, string $change): void
    {
        $slot = $action === 'delete' ? $this->slot() : null;
        $this->commitFixtures();
        $writer = $this->writer();
        $before = $this->state();
        DB::beginTransaction();
        $snapshot = $this->evidence($change);
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($writer, $change, $snapshot, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $this->assertSame(0, $writer->transactionLevel());
            $this->assertFalse($writer->getPdo()->inTransaction());
            $this->changeEvidence($writer, $change);
            $this->assertNotEquals($snapshot, $this->evidence($change, $writer), 'The independent writer committed different authority evidence.');
            $this->assertSame($snapshot, $this->evidence($change), 'The ordinary main RR read is demonstrably stale.');
        });
        try {
            $this->actingAs($this->manager)->withSession(['staff_availability_result' => ['action' => 'stale']])
                ->commandRequest($action, $this->worker, $slot)->assertForbidden()->assertSessionMissing('staff_availability_result');
            $this->assertTrue($fired);
            $this->assertSame($before, $this->state());
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('availability_evidence_writer');
        }
        $this->assertNotEquals($snapshot, $this->evidence($change));
        $this->assertSame($before, $this->state());
    }

    public static function committedRevocations(): array
    {
        $cases = [];
        foreach (['create', 'delete'] as $action) {
            foreach (['actor approval', 'exact grant', 'subject approval', 'subject active profile', 'subject site membership', 'active source site'] as $change) {
                $cases[$action.' after '.$change] = [$action, $change];
            }
        }

        return $cases;
    }

    public function test_deleted_row_owner_is_rechecked_after_the_current_command_wait(): void
    {
        $slot = $this->slot();
        $this->commitFixtures();
        $writer = $this->writer();
        $beforeAudit = DB::table('audit_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        DB::beginTransaction();
        $this->assertSame($this->worker->id, (int) DB::table('staff_availabilities')->where('id', $slot->id)->value('user_id'));
        $fired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($writer, $slot, &$fired): void {
            if ($fired || ! str_contains($sql, 'hr_payroll_run_mutexes')) {
                return;
            }
            $fired = true;
            $writer->table('staff_availabilities')->where('id', $slot->id)->update(['user_id' => $this->manager->id]);
            $this->assertSame($this->worker->id, (int) DB::table('staff_availabilities')->where('id', $slot->id)->value('user_id'));
        });
        try {
            $this->actingAs($this->manager)->delete(route('staff.availability.destroy', [$this->worker, $slot]))
                ->assertNotFound()->assertSessionMissing('staff_availability_result');
            $this->assertTrue($fired);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('availability_evidence_writer');
        }
        $this->assertSame($this->manager->id, (int) $slot->fresh()->user_id);
        $this->assertSame($beforeAudit, DB::table('audit_logs')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }

    #[DataProvider('actions')]
    public function test_genuine_held_site_evidence_is_a_recoverable_nowait_conflict_without_mutation(string $action): void
    {
        $slot = $action === 'delete' ? $this->slot() : null;
        $this->commitFixtures();
        $before = $this->state();
        $writer = $this->writer();
        $writer->beginTransaction();
        $writer->table('sites')->where('id', $this->site->id)->lockForUpdate()->first();
        try {
            $this->actingAs($this->manager)->commandRequest($action, $this->worker, $slot, json: true)
                ->assertUnprocessable()->assertJsonValidationErrors('availability')->assertSessionMissing('staff_availability_result');
            $this->assertSame($before, $this->state());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $writer->rollBack();
            DB::purge('availability_evidence_writer');
        }
    }

    public function test_invalid_follow_up_clears_a_previous_committed_receipt_without_changing_the_saved_window(): void
    {
        $this->commitFixtures();
        $this->actingAs($this->worker)->post(route('staff.availability.store', $this->worker), $this->window())
            ->assertRedirect()->assertSessionHas('staff_availability_result.action', 'create');
        $before = $this->state();
        $this->postJson(route('staff.availability.store', $this->worker), [...$this->window(), 'ends_next_day' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at')->assertSessionMissing('staff_availability_result');
        $this->assertSame($before, $this->state());
    }

    public function test_intentional_duplicate_and_overlap_creates_keep_the_existing_additive_pattern_semantics(): void
    {
        $this->commitFixtures();
        $this->actingAs($this->worker);
        $ids = [];
        foreach ([$this->window(), $this->window(), [...$this->window(), 'starts_at' => '22:17']] as $window) {
            $response = $this->post(route('staff.availability.store', $this->worker), $window)
                ->assertRedirect()->assertSessionHasNoErrors();
            $receipt = session('staff_availability_result');
            $this->assertIsArray($receipt);
            $this->assertSame('create', $receipt['action']);
            $ids[] = $receipt['availability_id'];
        }
        $this->assertCount(3, array_unique($ids));
        $this->assertDatabaseCount('staff_availabilities', 3);
    }

    public function test_post_commit_presentation_failure_cannot_make_the_saved_window_look_failed(): void
    {
        $this->commitFixtures();
        $this->actingAs($this->worker)->post(route('staff.availability.store', $this->worker), $this->window())
            ->assertRedirect()->assertSessionHasNoErrors();
        $slot = StaffAvailability::where('user_id', $this->worker->id)->sole();
        $before = $this->state();
        session()->forget('staff_availability_result');
        $faulty = new class extends StaffAvailability
        {
            public function getAttribute($key)
            {
                if ($key === 'starts_at') {
                    throw new RuntimeException('Private presentation detail must never escape.');
                }

                return parent::getAttribute($key);
            }
        };
        $faulty->setRawAttributes($slot->getRawOriginal(), true);
        $faulty->exists = true;
        $response = (new ReflectionMethod(StaffAvailabilityController::class, 'committedResult'))->invoke(
            app(StaffAvailabilityController::class), redirect('/weekly-pattern')->with('success', 'Availability added.'), true, 'create', $faulty,
        );
        $this->assertSame(url('/weekly-pattern'), $response->getTargetUrl());
        $this->assertSame('Availability added.', session('success'));
        $this->assertNull(session('staff_availability_result'));
        $this->assertSame($before, $this->state());
    }

    public function test_exact_reports_scope_preserves_current_foreign_site_subject_access_with_the_existing_update_grant(): void
    {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
        $permission = Permission::firstOrCreate(['key' => 'reports.viewAny'],
            ['description' => 'Broad reports', 'group' => 'Reports', 'module' => 'Operations']);
        $this->manager->roles()->sole()->permissions()->attach($permission);
        $this->manager = $this->manager->fresh();
        $this->commitFixtures();
        $response = $this->actingAs($this->manager)->post(route('staff.availability.store', $this->worker), $this->window())
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertReceipt($response, 'create', StaffAvailability::where('user_id', $this->worker->id)->sole());
    }

    public static function actions(): array
    {
        return ['create' => ['create'], 'delete' => ['delete']];
    }

    private function commandRequest(string $action, User $subject, ?StaffAvailability $slot, bool $json = false)
    {
        $method = $action === 'create' ? ($json ? 'postJson' : 'post') : ($json ? 'deleteJson' : 'delete');
        $url = $action === 'create' ? route('staff.availability.store', $subject) : route('staff.availability.destroy', [$subject, $slot]);

        return $this->{$method}($url, $action === 'create' ? $this->window() : []);
    }

    private function staff(array $permissions): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'created_by' => $user->id, 'updated_by' => $user->id, 'manager_user_id' => null]);
        $role = Role::create(['name' => 'availability-receipt-'.Str::uuid(), 'label' => 'Receipt fixture', 'type' => 'custom', 'level' => 10]);
        $role->permissions()->sync(collect($permissions)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
            ['description' => $key, 'group' => 'Staff', 'module' => 'Operations'])->id));
        $user->roles()->attach($role);

        return $user->fresh();
    }

    private function slot(): StaffAvailability
    {
        return StaffAvailability::create(['user_id' => $this->worker->id, ...$this->window()])->fresh();
    }

    private function window(): array
    {
        return ['day_of_week' => 1, 'starts_at' => '21:17', 'ends_at' => '07:43', 'ends_next_day' => true];
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->fixturesCommitted = true;
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function assertReceipt($response, string $action, StaffAvailability $slot): array
    {
        $receipt = [
            'action' => $action, 'staff_id' => (int) $slot->user_id, 'availability_id' => (int) $slot->id,
            'day_of_week' => (int) $slot->day_of_week, 'starts_at' => substr($slot->starts_at, 0, 5),
            'ends_at' => substr($slot->ends_at, 0, 5), 'ends_next_day' => (bool) $slot->ends_next_day,
        ];
        $response->assertSessionHas('staff_availability_result', $receipt);
        $this->assertSame($receipt, session('staff_availability_result'));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());

        return $receipt;
    }

    private function state(): array
    {
        return collect(['staff_availabilities', 'audit_logs'])->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ])->all();
    }

    private function writer(): Connection
    {
        config(['database.connections.availability_evidence_writer' => DB::connection()->getConfig()]);
        DB::purge('availability_evidence_writer');
        $writer = DB::connection('availability_evidence_writer');
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS selected_database')->selected_database);
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }

    private function evidence(string $change, ?Connection $connection = null): mixed
    {
        $connection ??= DB::connection();

        return match ($change) {
            'actor approval' => $connection->table('users')->where('id', $this->manager->id)->value('approved_at'),
            'exact grant' => $connection->table('role_permission')->where('role_id', $this->manager->roles()->sole()->id)
                ->where('permission_id', Permission::where('key', 'staff.availability.updateAny')->sole()->id)->count(),
            'subject approval' => $connection->table('users')->where('id', $this->worker->id)->value('approved_at'),
            'subject active profile' => $connection->table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('is_active'),
            'subject site membership' => $connection->table('hr_employee_profiles')->where('user_id', $this->worker->id)->value('primary_site_id'),
            'active source site' => $connection->table('sites')->where('id', $this->site->id)->value('is_active'),
        };
    }

    private function changeEvidence(Connection $writer, string $change): void
    {
        match ($change) {
            'actor approval' => $writer->table('users')->where('id', $this->manager->id)->update(['approved_at' => null]),
            'exact grant' => $writer->table('role_permission')->where('role_id', $this->manager->roles()->sole()->id)
                ->where('permission_id', Permission::where('key', 'staff.availability.updateAny')->sole()->id)->delete(),
            'subject approval' => $writer->table('users')->where('id', $this->worker->id)->update(['approved_at' => null]),
            'subject active profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['is_active' => false]),
            'subject site membership' => $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)
                ->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']),
            'active source site' => $writer->table('sites')->where('id', $this->site->id)->update(['is_active' => false]),
        };
    }
}
