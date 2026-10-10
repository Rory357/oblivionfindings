<?php

namespace Tests\Feature\Sites;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Models\AppSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteStaffRequirement;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

class SiteStaffRequirementCopyIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $sourceSite;

    private SiteStaffRequirement $source;

    private User $actor;

    private HrComplianceRequirement $qualification;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 04:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland',
            'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Http::preventStrayRequests();
        Queue::fake();
        Notification::fake();
        $this->sourceSite = Site::factory()->create(['type' => 'house', 'is_active' => true,
            'archived' => false, 'archived_at' => null]);
        $this->actor = User::factory()->create(['role' => 'coordinator', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'site-copy-'.Str::uuid(), 'label' => 'Site copy fixture', 'level' => 10, 'type' => 'custom']);
        $this->actor->roles()->attach($role);
        foreach (['sites.create', 'sites.viewAny'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'sites', 'module' => 'Sites']);
            $this->actor->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->sourceSite->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null,
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
        $this->qualification = HrComplianceRequirement::factory()->create(['code' => 'SITE_COPY_'.Str::upper(Str::random(12)),
            'check_type' => 'credential', 'is_active' => true]);
        $this->source = $this->requirement($this->sourceSite);
        $this->actingAs($this->actor->fresh());
    }

    protected function tearDown(): void
    {
        try {
            DB::purge('site_copy_writer');
            if ($this->committed && DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
            Carbon::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function copyMappings(): array
    {
        return ['mapped legacy choice' => [true], 'unmapped legacy choice' => [false]];
    }

    #[DataProvider('copyMappings')]
    public function test_actual_projected_copy_preserves_unset_and_configured_choices_without_changing_source(bool $mapped): void
    {
        if (! $mapped) {
            $this->source->update(['hr_compliance_requirement_id' => null]);
        }
        AppSetting::updateOrCreate(['key' => HrEligibilityRuleSettings::KEY], ['value' => ['version' => 1,
            'values' => [...HrEligibilityRuleSettings::DEFAULTS, 'house_qualification_approach' => 'all_workers']]]);
        $configured = $this->requirement($this->sourceSite, ['requirement_name' => 'Explicit minimum copy',
            'applicability_mode' => 'minimum_staff', 'minimum_qualified_staff' => 2]);
        $before = $this->state();
        $queue = $this->queueState();
        $rows = [];
        $this->get(route('sites.index'))->assertOk()->assertInertia(function (Assert $page) use (&$rows): void {
            $page->where('addSite.copyableSites', function ($sites) use (&$rows): bool {
                $rows = collect(collect($sites)->firstWhere('id', $this->sourceSite->id)['credentials'])->all();

                return true;
            });
        });
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
        $unset = collect($rows)->firstWhere('source_requirement_id', $this->source->id);
        $this->assertSame($this->source->fresh()->copyRevision(), $unset['source_revision']);
        $this->assertNull($unset['applicability_mode']);
        $credentials = array_map(fn ($row) => ['key' => 'copied', ...$row], $rows);
        $this->commitFixtures();
        $this->withProductionManager(function () use ($credentials): void {
            $this->postJson(route('sites.store'), $this->body($credentials))->assertRedirect()->assertSessionHas('created_site_id');
        });
        $created = Site::query()->where('name', 'DEVELOPMENT ONLY copied House')->sole();
        $this->assertNotSame($this->sourceSite->id, $created->id);
        $saved = SiteStaffRequirement::query()->where('site_id', $created->id)->orderBy('id')->get();
        $this->assertCount(2, $saved);
        foreach ([$this->source, $configured] as $original) {
            $copy = $saved->firstWhere('requirement_name', $original->requirement_name);
            $this->assertNotNull($copy);
            $this->assertSame($original->category, $copy->category);
            $this->assertSame($original->hr_compliance_requirement_id, $copy->hr_compliance_requirement_id);
            $this->assertSame($original->applicability_mode, $copy->applicability_mode);
            $this->assertSame($original->minimum_qualified_staff, $copy->minimum_qualified_staff);
            $this->assertSame((int) $original->expiry_period_months, (int) $copy->expiry_period_months);
        }
        foreach ($before as $table => $oldRows) {
            $ids = array_column($oldRows, 'id');
            $actual = $this->state()[$table];
            if ($table !== 'permission_user') {
                $actual = array_values(array_filter($actual, fn ($row) => in_array($row['id'], $ids, true)));
            }
            $this->assertSame($oldRows, $actual, 'Prior rows: '.$table);
        }
        $this->assertSame($before['shifts'], $this->state()['shifts']);
    }

    public static function invalidCopies(): array
    {
        return ['new blank with forged flag' => ['new'], 'missing copy Site' => ['site'],
            'missing source identity' => ['id'], 'missing revision' => ['revision'],
            'changed name' => ['name'], 'changed category' => ['category'],
            'changed expiry' => ['expiry'], 'changed mapping' => ['mapping'], 'blank mode with a count' => ['count'],
            'configured source cannot claim blank' => ['configured']];
    }

    #[DataProvider('invalidCopies')]
    public function test_new_forged_or_changed_blank_copy_is_rejected_without_any_write(string $change): void
    {
        if ($change === 'configured') {
            $this->source->update(['applicability_mode' => 'all_workers']);
        }
        $credential = $this->credential();
        $body = $this->body([$credential]);
        match ($change) {
            'new' => $body = $this->body([['key' => 'new', 'name' => 'New blank', 'category' => 'mandatory',
                'applicability_mode' => null, 'copied_unset_applicability' => true]], null),
            'site' => $body['copy_from'] = null,
            'id' => $body['credentials'][0]['source_requirement_id'] = null,
            'revision' => $body['credentials'][0]['source_revision'] = null,
            'name' => $body['credentials'][0]['name'] = 'Changed copy',
            'category' => $body['credentials'][0]['category'] = 'recommended',
            'expiry' => $body['credentials'][0]['expiry_period_months'] = 6,
            'mapping' => $body['credentials'][0]['hr_compliance_requirement_id'] = null,
            'count' => $body['credentials'][0]['minimum_qualified_staff'] = 2,
            'configured' => null,
        };
        $before = $this->state();
        $queue = $this->queueState();
        $this->postJson(route('sites.store'), $body)->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.0.applicability_mode');
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public function test_source_site_and_requirement_ownership_are_both_enforced(): void
    {
        $outside = Site::factory()->create(['type' => 'house', 'is_active' => true, 'archived' => false]);
        $foreign = $this->requirement($outside);
        $before = $this->state();
        $queue = $this->queueState();
        $this->postJson(route('sites.store'), $this->body([$this->credential($foreign)], $outside->id))->assertNotFound();
        $this->postJson(route('sites.store'), $this->body([$this->credential($foreign)]))->assertUnprocessable()
            ->assertJsonValidationErrors('credentials.0.applicability_mode');
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    public static function currentChanges(): array
    {
        return ['source changed after display' => ['source'], 'source became configured' => ['choice'],
            'source became inactive' => ['inactive'], 'source Site archived' => ['archived'],
            'read grant revoked' => ['read'], 'create grant revoked' => ['create'],
            'approved Site assignment removed' => ['profile']];
    }

    #[DataProvider('currentChanges')]
    public function test_current_copy_checks_reject_independent_changes_despite_primed_rr(string $change): void
    {
        $body = $this->body([$this->credential()]);
        $this->actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertTrue($this->actor->canDo('sites.create'));
        $this->assertTrue($this->actor->canDo('sites.viewAny'));
        $this->actingAs($this->actor);
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withProductionManager(function () use ($change, $body, $writer): void {
            DB::beginTransaction();
            $old = $this->state();
            $writer->transaction(function () use ($change, $writer): void {
                match ($change) {
                    'source' => $writer->table('site_staff_requirements')->where('id', $this->source->id)->update(['description' => 'Changed after display']),
                    'choice' => $writer->table('site_staff_requirements')->where('id', $this->source->id)->update(['applicability_mode' => 'all_workers']),
                    'inactive' => $writer->table('site_staff_requirements')->where('id', $this->source->id)->update(['is_active' => false]),
                    'archived' => $writer->table('sites')->where('id', $this->sourceSite->id)->update(['archived' => true]),
                    'profile' => $writer->table('hr_employee_profiles')->where('user_id', $this->actor->id)->update(['primary_site_id' => null]),
                    'read', 'create' => $writer->table('permission_user')->where('user_id', $this->actor->id)
                        ->where('permission_id', Permission::query()->where('key', 'sites.'.($change === 'read' ? 'viewAny' : 'create'))->value('id'))
                        ->update(['allowed' => false]),
                };
            });
            $this->assertSame($old, $this->state(), 'Ordinary reads retain the old RR snapshot.');
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queueState();
            $response = $this->postJson(route('sites.store'), $body);
            if (in_array($change, ['read', 'create'], true)) {
                $response->assertForbidden();
            } elseif (in_array($change, ['archived', 'profile'], true)) {
                $response->assertNotFound();
            } else {
                $response->assertUnprocessable()->assertJsonValidationErrors('credentials.0.applicability_mode');
            }
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($queue, $this->queueState());
            DB::commit();
            $this->assertSame($current, $this->state());
        });
    }

    public function test_late_requirement_veto_rolls_back_the_new_site_and_all_earlier_copy_effects(): void
    {
        $body = $this->body([$this->credential(), ['key' => 'new', 'name' => 'Refused new requirement',
            'category' => 'mandatory', 'applicability_mode' => 'all_workers']]);
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queueState();
        $original = Model::getEventDispatcher();
        $scoped = clone $original;
        $scoped->listen('eloquent.saving: '.SiteStaffRequirement::class, fn (SiteStaffRequirement $row) => $row->requirement_name === 'Refused new requirement' ? false : null);
        Model::setEventDispatcher($scoped);
        try {
            $this->withProductionManager(fn () => $this->postJson(route('sites.store'), $body)
                ->assertUnprocessable()->assertJsonValidationErrors('credentials.1'));
        } finally {
            Model::setEventDispatcher($original);
        }
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queueState());
    }

    private function requirement(Site $site, array $values = []): SiteStaffRequirement
    {
        return SiteStaffRequirement::create(['site_id' => $site->id, 'requirement_name' => 'Legacy '.Str::uuid(),
            'category' => 'mandatory', 'certification_required' => true, 'expiry_period_months' => 12,
            'hr_compliance_requirement_id' => $this->qualification->id, 'applicability_mode' => null,
            'minimum_qualified_staff' => null, 'is_active' => true, ...$values])->fresh();
    }

    private function credential(?SiteStaffRequirement $row = null): array
    {
        $row ??= $this->source;

        return ['key' => 'copied', 'name' => $row->requirement_name, 'category' => $row->category,
            'expiry_period_months' => $row->expiry_period_months, 'hr_compliance_requirement_id' => $row->hr_compliance_requirement_id,
            'applicability_mode' => null, 'minimum_qualified_staff' => null,
            'source_requirement_id' => $row->id, 'source_revision' => $row->copyRevision()];
    }

    private function body(array $credentials, int|null|false $copyFrom = false): array
    {
        return ['name' => 'DEVELOPMENT ONLY copied House', 'type' => 'house', 'is_active' => true, '_modal' => true,
            'copy_from' => $copyFrom === false ? $this->sourceSite->id : $copyFrom, 'credentials' => $credentials];
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['sites', 'site_staff_requirements', 'site_coverage_requirements', 'hr_employee_profiles', 'permission_user',
            'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations', 'shifts'];
        $state = [];
        foreach ($tables as $table) {
            $rows = ($connection ?? DB::connection())->table($table)->get()->map(fn ($row) => (array) $row)->all();
            usort($rows, fn ($left, $right) => strcmp(serialize($left), serialize($right)));
            $state[$table] = $rows;
        }

        return $state;
    }

    private function queueState(): array
    {
        return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
            'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
        ], $entries))->all();
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
        $name = 'site_copy_writer';
        config(['database.connections.'.$name => array_replace(DB::connection()->getConfig(), ['name' => $name])]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame($writer->getDatabaseName(), $writer->selectOne('SELECT DATABASE() AS db')->db);
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());

        return $writer;
    }

    private function withProductionManager(callable $proof): void
    {
        $connection = DB::connection();
        $testing = app('db.transactions');
        $production = new DatabaseTransactionsManager;
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->getPdo()->inTransaction());
        app()->instance('db.transactions', $production);
        $connection->setTransactionManager($production);
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
}
