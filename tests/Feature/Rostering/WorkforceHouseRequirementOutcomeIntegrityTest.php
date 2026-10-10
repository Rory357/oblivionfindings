<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Http\Controllers\Sites\SiteComplianceController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Assurance\NzsAssuranceResolver;
use App\Services\Assurance\SiteCertificationService;
use App\Services\Eligibility\WorkforceEligibilitySources;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Confirm only persisted House controls and their durable source refresh intent. */
class WorkforceHouseRequirementOutcomeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $actor;

    private HrComplianceRequirement $canonical;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 03:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland',
            'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = $this->person();
        $this->canonical = HrComplianceRequirement::factory()->create(['check_type' => 'credential', 'is_active' => true,
            'hard_stop' => false, 'validity_months' => null, 'reference_id' => null]);
        $this->actingAs($this->actor);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_actual_root_crud_confirms_full_values_nulls_omissions_noop_and_exact_source_versions(): void
    {
        $prior = $this->requirement(['requirement_name' => 'Untouched prior House control']);
        $this->commitFixtures();
        $this->withManager(function () use ($prior): void {
            $priorRaw = $prior->fresh()->getRawOriginal();
            $body = $this->body(['requirement_name' => "\u{200D} House control \u{034F}", 'description' => ' guidance ',
                'hr_compliance_requirement_id' => (string) $this->canonical->id]);
            $this->post($this->url('store'), $body)
                ->assertRedirect()->assertSessionHasNoErrors();
            $row = SiteStaffRequirement::where('site_id', $this->site->id)->where('requirement_name', 'House control')->sole();
            $first = $this->assertReceipt('created', $row, 'saved', true);
            $this->assertSame($body['request_id'], $first['request_id']);
            $this->assertSame('guidance', $first['values']['description']);
            $this->assertSame(1, $first['refresh']['source_version']);
            $this->assertSource($row, 1);
            $queue = $this->queue();
            $this->put($this->url('update', $row), ['request_id' => (string) Str::uuid(), 'description' => 'Changed guidance'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $row->refresh();
            $guide = $this->assertReceipt('updated', $row, 'saved', true);
            $this->assertNull($guide['refresh']);
            $this->assertSame($this->canonical->id, $row->hr_compliance_requirement_id);
            $this->assertSame('minimum_staff', $row->applicability_mode);
            $this->assertSame(2, $row->minimum_qualified_staff);
            $this->assertSame($queue, $this->queue());
            $this->assertSource($row, 1);
            $this->put($this->url('update', $row), ['request_id' => (string) Str::uuid(), 'category' => 'recommended',
                'hr_compliance_requirement_id' => (string) $this->canonical->id])->assertSessionHasNoErrors();
            $row->refresh();
            $this->assertSame($this->canonical->id, $this->assertReceipt('updated', $row, 'saved', true)['values']['hr_compliance_requirement_id']);
            $this->assertSource($row, 2);
            $this->put($this->url('update', $row), ['request_id' => (string) Str::uuid(), 'description' => null,
                'hr_compliance_requirement_id' => null, 'applicability_mode' => 'all_workers', 'minimum_qualified_staff' => null,
                'certification_required' => false, 'expiry_period_months' => null])->assertSessionHasNoErrors();
            $row->refresh();
            $cleared = $this->assertReceipt('updated', $row, 'saved', true);
            foreach (['description', 'hr_compliance_requirement_id', 'minimum_qualified_staff', 'expiry_period_months'] as $key) {
                $this->assertNull($cleared['values'][$key]);
            }
            $this->assertFalse($cleared['values']['certification_required']);
            $this->assertSame(3, $cleared['refresh']['source_version']);
            $this->assertSource($row, 3);
            $state = $this->state();
            $queue = $this->queue();
            $this->put($this->url('update', $row), ['request_id' => (string) Str::uuid()])->assertSessionHasNoErrors();
            $unchanged = $this->assertReceipt('updated', $row->fresh(), 'unchanged', false);
            $this->assertNull($unchanged['refresh']);
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
            $id = (string) Str::uuid();
            $this->delete($this->url('destroy', $row), ['request_id' => $id])->assertSessionHasNoErrors();
            $this->assertFalse(SiteStaffRequirement::whereKey($row->id)->exists());
            $deleted = session('house_qualification_result');
            $this->assertSame('deleted', $deleted['action']);
            $this->assertSame('deleted', $deleted['outcome']);
            $this->assertTrue($deleted['changed']);
            $this->assertNull($deleted['values']);
            $this->assertSame($id, $deleted['request_id']);
            $this->assertSame($this->actor->id, $deleted['actor_id']);
            $this->assertSame($this->site->id, $deleted['site_id']);
            $this->assertSame($row->id, $deleted['requirement_id']);
            $this->assertSame(4, $deleted['refresh']['source_version']);
            $this->assertSource($row, 4, true);
            $this->assertSame($priorRaw, $prior->fresh()->getRawOriginal());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_create_defaults_and_omitted_legacy_update_preserve_supported_nullable_controls(): void
    {
        $legacy = $this->requirement(['requirement_name' => 'Legacy unresolved mode', 'applicability_mode' => null, 'minimum_qualified_staff' => null]);
        config(['hr.eligibility_rules.house_qualification_approach' => 'all_workers']);
        $this->commitFixtures();
        $this->withManager(function () use ($legacy): void {
            $this->post($this->url('store'), ['request_id' => (string) Str::uuid(), 'requirement_name' => 'New default control', 'category' => 'recommended'])
                ->assertSessionHasNoErrors();
            $row = SiteStaffRequirement::where('requirement_name', 'New default control')->sole();
            $values = $this->assertReceipt('created', $row, 'saved', true)['values'];
            $this->assertSame('all_workers', $values['applicability_mode']);
            $this->assertTrue($values['is_active']);
            $this->assertFalse($values['certification_required']);
            foreach (['description', 'expiry_period_months', 'hr_compliance_requirement_id', 'minimum_qualified_staff'] as $key) {
                $this->assertNull($values[$key]);
            }
            $queue = $this->queue();
            $this->put($this->url('update', $legacy), ['request_id' => (string) Str::uuid(), 'description' => 'Legacy guidance'])
                ->assertSessionHasNoErrors();
            $receipt = $this->assertReceipt('updated', $legacy->fresh(), 'saved', true);
            $this->assertNull($receipt['values']['applicability_mode']);
            $this->assertNull($receipt['values']['minimum_qualified_staff']);
            $this->assertNull($receipt['refresh']);
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_own_requester_projection_foreign_privacy_and_validation_clear_are_exact(): void
    {
        $other = $this->person();
        $this->commitFixtures();
        $this->withManager(function () use ($other): void {
            $this->post($this->url('store'), $this->body())->assertSessionHasNoErrors();
            $receipt = session('house_qualification_result');
            $this->get(route('sites.show', $this->site))->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('flash.house_qualification_result', fn ($value) => $value->all() == $receipt));
            $this->actingAs($other)->withSession(['house_qualification_result' => $receipt])->get(route('sites.show', $this->site))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->where('flash.house_qualification_result', null));
            $state = $this->state();
            $queue = $this->queue();
            $this->actingAs($this->actor)->withSession(['house_qualification_result' => $receipt])->postJson($this->url('store'), [])
                ->assertUnprocessable()->assertJsonValidationErrors('requirement_name')->assertSessionMissing('house_qualification_result');
            $this->postJson($this->url('store'), $this->body(['request_id' => 'not-a-uuid']))
                ->assertUnprocessable()->assertJsonValidationErrors('request_id')->assertSessionMissing('house_qualification_result');
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
        });
    }

    #[DataProvider('nestedOutcomes')]
    public function test_nested_success_never_claims_commit_and_outer_rollback_removes_row_intent_and_delivery(bool $commit): void
    {
        $this->commitFixtures();
        $state = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($commit, $state, $queue): void {
            DB::beginTransaction();
            $this->withSession(['house_qualification_result' => ['actor_id' => $this->actor->id]])
                ->post($this->url('store'), $this->body())->assertSessionHasNoErrors()->assertSessionMissing('house_qualification_result');
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $this->assertSame($queue, $this->queue());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('house_qualification_result'));
            if ($commit) {
                $row = SiteStaffRequirement::where('site_id', $this->site->id)->sole();
                $this->assertSource($row, 1);
                $this->assertNotSame($queue, $this->queue());
            } else {
                $this->assertSame($state, $this->state());
                $this->assertSame($queue, $this->queue());
            }
        });
    }

    public static function nestedOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    public function test_raw_pdo_root_guards_withhold_the_same_controller_result_and_clear_old_receipt(): void
    {
        $this->commitFixtures();
        $this->withManager(function (): void {
            $this->post($this->url('store'), $this->body())->assertSessionHasNoErrors();
            $result = session('house_qualification_result');
            $controller = app(SiteComplianceController::class);
            $request = Request::create($this->url('store'), 'POST', ['request_id' => (string) Str::uuid()]);
            $request->setUserResolver(fn () => $this->actor);
            $request->setLaravelSession(session()->driver());
            $state = $this->state();
            $queue = $this->queue();
            $pdo = DB::connection()->getPdo();
            $pdo->beginTransaction();
            try {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertTrue($pdo->inTransaction());
                $this->assertFalse((new ReflectionMethod($controller, 'beginStaffRequirementReceipt'))->invoke($controller, $request));
                $this->assertFalse((new ReflectionMethod($controller, 'staffRequirementReceiptIsRoot'))->invoke($controller, true));
                (new ReflectionMethod($controller, 'withStaffRequirementReceipt'))->invoke($controller,
                    $request, redirect('/'), true, $this->actor->id, $result);
                $this->assertNull(session('house_qualification_result'));
            } finally {
                $pdo->rollBack();
            }
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
        });
    }

    public function test_contained_postcommit_projection_failure_keeps_actual_saved_row_and_durable_intent(): void
    {
        $this->app->instance(SiteComplianceController::class, new class(app(SiteCertificationService::class), app(NzsAssuranceResolver::class)) extends SiteComplianceController
        {
            protected function projectStaffRequirementResult(array $result): array
            {
                throw new RuntimeException('Synthetic projection fault');
            }
        });
        $this->commitFixtures();
        $this->withManager(function (): void {
            $this->post($this->url('store'), $this->body())->assertSessionHasNoErrors()->assertSessionMissing('house_qualification_result');
            $row = SiteStaffRequirement::where('site_id', $this->site->id)->sole();
            $this->assertSame('House control', $row->requirement_name);
            $this->assertSame($this->canonical->id, $row->hr_compliance_requirement_id);
            $this->assertSource($row, 1);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    #[DataProvider('rowFailures')]
    public function test_model_refusal_altered_values_and_exception_cannot_report_or_keep_partial_work(string $action, string $mode): void
    {
        $row = $action === 'create' ? null : $this->requirement();
        $this->commitFixtures();
        $state = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($action, $mode, $row): void {
            $event = $action === 'delete' ? 'deleting' : 'saving';
            $this->withListener(SiteStaffRequirement::class, $event, function (SiteStaffRequirement $record) use ($mode) {
                if ($mode === 'alter') {
                    $record->description = 'Observer changed requested guidance';

                    return null;
                }
                if ($mode === 'throw') {
                    throw new RuntimeException('Synthetic saved-source exception');
                }

                return false;
            }, function () use ($action, $mode, $row): void {
                $this->withSession(['house_qualification_result' => ['action' => 'prior']]);
                $response = $this->mutate($action, $row);
                $response->assertStatus($mode === 'throw' ? 500 : 409)->assertSessionMissing('house_qualification_result');
            });
        });
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function rowFailures(): array
    {
        return ['create refused' => ['create', 'veto'], 'update refused' => ['update', 'veto'], 'delete refused' => ['delete', 'veto'],
            'update altered' => ['update', 'alter'], 'delete altered' => ['delete', 'alter'], 'observer exception' => ['update', 'throw']];
    }

    #[DataProvider('intentFailures')]
    public function test_refused_or_altered_refresh_intent_rolls_back_full_requirement_and_complete_queue(string $action, string $mode): void
    {
        $row = $action === 'create' ? null : $this->requirement();
        $this->commitFixtures();
        $state = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($action, $mode, $row): void {
            $this->withListener(WorkforceEligibilityRecheck::class, 'saving', function (WorkforceEligibilityRecheck $intent) use ($mode) {
                if ($intent->source_type !== 'site_staff_requirements') {
                    return null;
                }
                if ($mode === 'veto') {
                    return false;
                }
                match ($mode) {
                    'fingerprint' => $intent->source_fingerprint = str_repeat('f', 64),
                    'version' => $intent->source_version += 1,
                    'site' => $intent->site_ids = [],
                };

                return null;
            }, function () use ($action, $row): void {
                $this->mutate($action, $row)->assertConflict()->assertSessionMissing('house_qualification_result');
            });
        });
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function intentFailures(): array
    {
        return ['create intent refused' => ['create', 'veto'], 'update intent refused' => ['update', 'veto'], 'delete intent refused' => ['delete', 'veto'],
            'fingerprint altered' => ['update', 'fingerprint'], 'version altered' => ['update', 'version'], 'Site scope altered' => ['update', 'site']];
    }

    private function mutate(string $action, ?SiteStaffRequirement $row)
    {
        return match ($action) {
            'create' => $this->postJson($this->url('store'), $this->body()),
            'update' => $this->putJson($this->url('update', $row), ['request_id' => (string) Str::uuid(),
                'description' => 'Requested guidance', 'hr_compliance_requirement_id' => null]),
            'delete' => $this->deleteJson($this->url('destroy', $row), ['request_id' => (string) Str::uuid()]),
        };
    }

    private function person(): User
    {
        $user = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'house-outcome-'.Str::uuid(), 'label' => 'House outcome fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach (['sites.viewAny', 'sites.update'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'sites', 'module' => 'Sites']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function body(array $values = []): array
    {
        return ['request_id' => (string) Str::uuid(), 'requirement_name' => 'House control', 'category' => 'mandatory',
            'description' => 'Guidance', 'certification_required' => true, 'expiry_period_months' => 12,
            'hr_compliance_requirement_id' => $this->canonical->id, 'applicability_mode' => 'minimum_staff',
            'minimum_qualified_staff' => 2, ...$values];
    }

    private function requirement(array $values = []): SiteStaffRequirement
    {
        $body = $this->body($values);
        unset($body['request_id']);

        return SiteStaffRequirement::create(['site_id' => $this->site->id, 'is_active' => true, ...$body]);
    }

    private function url(string $action, ?SiteStaffRequirement $row = null): string
    {
        return route('sites.staff_requirements.'.$action, $row ? ['site' => $this->site, 'requirement' => $row] : ['site' => $this->site]);
    }

    private function values(SiteStaffRequirement $row): array
    {
        return ['site_id' => (int) $row->site_id, 'requirement_name' => $row->requirement_name, 'category' => $row->category,
            'description' => $row->description, 'certification_required' => (bool) $row->certification_required,
            'expiry_period_months' => $row->expiry_period_months === null ? null : (int) $row->expiry_period_months,
            'hr_compliance_requirement_id' => $row->hr_compliance_requirement_id,
            'applicability_mode' => $row->applicability_mode, 'minimum_qualified_staff' => $row->minimum_qualified_staff,
            'is_active' => (bool) $row->is_active];
    }

    private function assertReceipt(string $action, SiteStaffRequirement $row, string $outcome, bool $changed): array
    {
        $receipt = session('house_qualification_result');
        $this->assertSame(['version', 'scope', 'request_id', 'actor_id', 'action', 'site_id', 'requirement_id',
            'outcome', 'changed', 'values', 'refresh', 'committed_at'], array_keys($receipt));
        $this->assertSame(1, $receipt['version']);
        $this->assertSame('house_requirement', $receipt['scope']);
        $this->assertTrue(Str::isUuid($receipt['request_id']));
        $this->assertSame($this->actor->id, $receipt['actor_id']);
        $this->assertSame($this->site->id, $receipt['site_id']);
        $this->assertSame($row->id, $receipt['requirement_id']);
        $this->assertSame($action, $receipt['action']);
        $this->assertSame($outcome, $receipt['outcome']);
        $this->assertSame($changed, $receipt['changed']);
        $this->assertSame($this->values($row), $receipt['values']);
        $this->assertSame('2026-10-09T03:00:00.000Z', $receipt['committed_at']);
        if ($receipt['refresh'] !== null) {
            $intent = $this->intent($row);
            $this->assertSame(['source_type' => 'site_staff_requirements', 'source_id' => $row->id,
                'source_fingerprint' => $intent->source_fingerprint, 'source_version' => $intent->source_version,
                'site_id' => $this->site->id], $receipt['refresh']);
        }

        return $receipt;
    }

    private function intent(SiteStaffRequirement $row): WorkforceEligibilityRecheck
    {
        return WorkforceEligibilityRecheck::where('source_type', $row->getTable())->where('source_id', $row->id)->sole();
    }

    private function assertSource(SiteStaffRequirement $row, int $version, bool $deleted = false): void
    {
        $intent = $this->intent($row);
        $source = app(WorkforceEligibilitySources::class)->describe($row, $deleted);
        $this->assertSame($source['fingerprint'], $intent->source_fingerprint);
        $this->assertSame($version, $intent->source_version);
        $this->assertSame([$this->site->id], $intent->site_ids);
        foreach (['user_ids', 'client_ids', 'shift_ids'] as $key) {
            $this->assertSame([], $intent->$key);
        }
        $this->assertFalse($intent->all_assigned);
        $this->assertSame('pending', $intent->status);
    }

    private function state(): array
    {
        $tables = ['site_staff_requirements', 'site_coverage_requirements', 'workforce_eligibility_rechecks',
            'workforce_eligibility_observations', 'sites', 'hr_compliance_requirements'];

        return array_combine($tables, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()
            ->map(fn ($row) => (array) $row)->all(), $tables));
    }

    private function queue(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
    }

    private function commitFixtures(): void
    {
        $connection = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(static::$isolatedMysqlDatabase, $connection->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($connection->getDatabaseName(), getmypid()));
        $this->assertSame($connection->getDatabaseName(), $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertSame(1, $connection->transactionLevel());
        DB::commit();
        $this->committed = true;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
    }

    private function withManager(callable $proof): void
    {
        $connection = DB::connection();
        $testing = app('db.transactions');
        $manager = new DatabaseTransactionsManager;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        app()->instance('db.transactions', $manager);
        $connection->setTransactionManager($manager);
        try {
            $proof();
        } finally {
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            app()->instance('db.transactions', $testing);
            $connection->setTransactionManager($testing);
        }
    }

    private function withListener(string $model, string $event, callable $listener, callable $proof): void
    {
        new $model;
        $original = Model::getEventDispatcher();
        $scoped = clone $original;
        $scoped->listen('eloquent.'.$event.': '.$model, $listener);
        Model::setEventDispatcher($scoped);
        try {
            $proof();
        } finally {
            Model::setEventDispatcher($original);
        }
    }
}
