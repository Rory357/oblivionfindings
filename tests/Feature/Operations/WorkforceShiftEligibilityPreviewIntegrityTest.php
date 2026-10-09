<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Advisory HTTP must assess the selected canonical Client without any saved intent. */
class WorkforceShiftEligibilityPreviewIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    private User $actor;

    private User $worker;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        ServiceContext::query()->get()->each(fn (ServiceContext $row) => $row->update(['is_active' => false]));
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['site_id' => null, 'type' => 'home_support', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->actor = $this->person(['rostering.viewAny', 'shifts.create', 'shifts.update']);
        $this->worker = $this->person();
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('preview_current_writer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_real_route_reports_mapped_missing_and_recorded_qualification_for_the_selected_client(): void
    {
        $canonical = HrComplianceRequirement::factory()->create(['code' => 'preview-'.Str::uuid(), 'name' => 'Selected Client credential',
            'check_type' => 'credential', 'is_active' => true, 'hard_stop' => false, 'reference_id' => null, 'validity_months' => null]);
        $this->requirement(['service_context_id' => null, 'hr_compliance_requirement_id' => $canonical->id]);
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview()->assertOk()->assertJsonPath('client_qualification_checked', true);
        $checks = $this->clientChecks($response->json());
        $this->assertCount(1, $checks);
        $this->assertSame($canonical->id, $checks[0]['hr_compliance_requirement_id']);
        $this->assertSame('block', $checks[0]['severity']);
        $this->assertSame('not_started', $checks[0]['qualification_status']);
        $this->assertFalse($checks[0]['overrideable']);
        $this->assertContains($checks[0]['message'], $response->json('blocked_reasons'));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());

        StaffCredential::create(['user_id' => $this->worker->id, 'type' => $canonical->code, 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01']);
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview()->assertOk()->assertJsonPath('client_qualification_checked', true);
        $this->assertSame([], $this->clientChecks($response->json()));
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
        $this->assertLegacyKeys($response->json());
    }

    #[DataProvider('contextPriority')]
    public function test_current_context_priority_matches_planning_and_only_general_or_exact_client_patterns_apply(string $kind): void
    {
        $alternate = ServiceContext::factory()->create(['site_id' => $this->site->id, 'type' => 'residential', 'is_active' => true]);
        $explicit = null;
        $expected = $this->context->id;
        if ($kind === 'explicit active') {
            $explicit = $alternate->id;
            $expected = $alternate->id;
        } elseif ($kind === 'inactive explicit falls back') {
            $alternate->update(['is_active' => false]);
            $explicit = $alternate->id;
        } elseif ($kind === 'configured global default') {
            $this->client->update(['service_context_id' => null]);
            AppSetting::updateOrCreate(['key' => 'service_context.default_id'], ['value' => (string) $alternate->id]);
            $alternate->update(['site_id' => null]);
            $expected = $alternate->id;
        } elseif ($kind === 'first active') {
            $this->client->update(['service_context_id' => null]);
            AppSetting::where('key', 'service_context.default_id')->delete();
        } elseif ($kind === 'no active context') {
            $this->context->update(['is_active' => false]);
            $alternate->update(['is_active' => false]);
            $this->client->update(['service_context_id' => null]);
            AppSetting::where('key', 'service_context.default_id')->delete();
            $expected = null;
        }
        $general = $this->requirement(['qualification_name' => 'General selected Client requirement', 'service_context_id' => null]);
        $wanted = [$general->id];
        if ($expected !== null) {
            $wanted[] = $this->requirement(['qualification_name' => 'Exact selected context requirement', 'service_context_id' => $expected])->id;
        }
        $different = $expected === $alternate->id ? $this->context->id : $alternate->id;
        $this->requirement(['qualification_name' => 'Private other context marker', 'service_context_id' => $different]);
        $this->requirement(['qualification_name' => 'Optional excluded marker', 'is_mandatory' => false, 'service_context_id' => null]);
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $this->requirement(['qualification_name' => 'Private other Client marker', 'client_id' => $other->id, 'service_context_id' => null]);
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview(['service_context_id' => $explicit])->assertOk();
        $checks = $this->clientChecks($response->json());
        $this->assertSame($wanted, array_column($checks, 'requirement_id'));
        foreach ($checks as $check) {
            $this->assertSame('unmapped', $check['qualification_status']);
            $this->assertSame('warning', $check['severity']);
            $this->assertTrue($check['requires_assignment_acknowledgement']);
        }
        foreach (['Private other context marker', 'Private other Client marker', 'Optional excluded marker'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function contextPriority(): array
    {
        return array_combine($names = ['explicit active', 'inactive explicit falls back', 'Client active', 'configured global default', 'first active', 'no active context'], array_map(fn ($name) => [$name], $names));
    }

    public function test_legacy_without_a_client_keeps_existing_fields_but_does_not_claim_client_qualification_checked(): void
    {
        $this->requirement(['service_context_id' => null]);
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview(['client_id' => null, 'service_context_id' => null])->assertOk()->assertJsonPath('client_qualification_checked', false);
        $this->assertSame([], $this->clientChecks($response->json()));
        $this->assertLegacyKeys($response->json());
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public function test_editing_legacy_omitted_client_uses_current_bound_client_and_excludes_only_its_own_shift(): void
    {
        $requirement = $this->requirement(['service_context_id' => null]);
        $shift = Shift::factory()->create([...$this->body(), 'status' => 'scheduled', 'created_by' => $this->actor->id]);
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview(['client_id' => null, 'service_context_id' => null, 'shift_id' => $shift->id])->assertOk()->assertJsonPath('client_qualification_checked', true);
        $this->assertSame([$requirement->id], array_column($this->clientChecks($response->json()), 'requirement_id'));
        $this->assertTrue(collect($response->json('checked_rules'))->firstWhere('rule', 'conflict')['passed']);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('boundaries')]
    public function test_canonical_client_site_existing_shift_and_worker_scope_deny_without_labels_or_writes(string $kind, int $status): void
    {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $other = Client::factory()->create(['site_id' => $outside->id, 'first_name' => 'Private foreign Client sentinel']);
        $this->requirement(['client_id' => $other->id, 'qualification_name' => 'Private foreign qualification sentinel', 'service_context_id' => null]);
        $values = [];
        if ($kind === 'Client Site mismatch with reports bypass') {
            $permission = Permission::firstOrCreate(['key' => 'reports.viewAny'], ['description' => 'reports.viewAny', 'group' => 'workforce', 'module' => 'Operations']);
            $this->actor->permissionOverrides()->attach($permission, ['allowed' => true]);
            $this->actor = $this->actor->fresh();
            $values['client_id'] = $other->id;
        } elseif ($kind === 'deleted selected Client') {
            $this->client->delete();
        } elseif ($kind === 'foreign existing Shift') {
            $values['shift_id'] = Shift::factory()->create(['client_id' => $other->id, 'site_id' => $outside->id, 'user_id' => null])->id;
        } else {
            $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
            if ($kind === 'target Site mismatch') {
                $this->actor->hrEmployeeProfile()->update(['secondary_site_ids' => [$outside->id]]);
                $this->actor = $this->actor->fresh();
            }
        }
        $state = $this->state();
        $queue = $this->queue();
        $response = $this->preview($values)->assertStatus($status);
        if ($status === 422) {
            $response->assertJsonValidationErrors('user_id');
        }
        foreach (['Private foreign Client sentinel', 'Private foreign qualification sentinel'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function boundaries(): array
    {
        return ['canonical mismatch' => ['Client Site mismatch with reports bypass', 403], 'deleted Client' => ['deleted selected Client', 403],
            'bound foreign Shift' => ['foreign existing Shift', 403], 'general worker' => ['general worker scope', 403], 'exact worker Site' => ['target Site mismatch', 422]];
    }

    #[DataProvider('unavailableMappings')]
    public function test_unavailable_evidence_returns_only_a_safe_advisory_error_without_raw_failure_or_foreign_names(string $kind): void
    {
        $canonical = HrComplianceRequirement::factory()->create(['name' => 'Private configured evidence sentinel',
            'check_type' => 'credential', 'is_active' => true, 'hard_stop' => false]);
        $this->requirement(['hr_compliance_requirement_id' => $canonical->id, 'service_context_id' => null]);
        $this->assertCount(1, $this->clientChecks($this->preview()->assertOk()->json()));
        $canonical->update(['check_type' => $kind === 'unsupported' ? 'unsupported' : 'credential', 'is_active' => $kind !== 'inactive']);
        $state = $this->state();
        $queue = $this->queue();
        $this->preview()->assertStatus(503)->assertExactJson(['message' => 'Eligibility checks are temporarily unavailable. Please try again shortly.']);
        $this->assertSame($state, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function unavailableMappings(): array
    {
        return ['inactive' => ['inactive'], 'unsupported' => ['unsupported']];
    }

    #[DataProvider('currentChanges')]
    public function test_primed_repeatable_read_rejects_independently_committed_actor_client_or_worker_revocation(string $kind): void
    {
        $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->requirement(['service_context_id' => null]);
        $this->commitFixtures();
        $writer = $this->writer();
        DB::beginTransaction();
        $stale = $this->actor->fresh()->load('permissionOverrides', 'roles.permissions', 'hrEmployeeProfile');
        $this->assertTrue($stale->canDo('shifts.create'));
        $this->assertNotNull(Client::find($this->client->id));
        $this->assertSame($this->site->id, $this->worker->fresh()->hrEmployeeProfile->primary_site_id);
        if ($kind === 'actor grants') {
            $ids = Permission::whereIn('key', ['shifts.create', 'shifts.update'])->pluck('id');
            $writer->table('permission_user')->where('user_id', $this->actor->id)->whereIn('permission_id', $ids)->update(['allowed' => false]);
        } elseif ($kind === 'actor approval') {
            $writer->table('users')->where('id', $this->actor->id)->update(['approved_at' => null]);
        } elseif ($kind === 'Client Site') {
            $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $outside->id]);
        } else {
            $writer->table('hr_employee_profiles')->where('user_id', $this->worker->id)->update(['primary_site_id' => $outside->id]);
        }
        $this->assertTrue($stale->canDo('shifts.create'), 'The supplied account still holds the primed old grant.');
        $state = $this->state($writer);
        $queue = $this->queue();
        $this->actor = $stale;
        $this->preview()->assertForbidden();
        $this->assertSame($state, $this->state($writer));
        $this->assertSame($queue, $this->queue());
        DB::rollBack();
    }

    public static function currentChanges(): array
    {
        return ['actor grants' => ['actor grants'], 'actor approval' => ['actor approval'], 'canonical Client Site' => ['Client Site'], 'worker scope' => ['worker Site']];
    }

    private function person(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'preview-'.Str::uuid(), 'label' => 'Synthetic preview participant', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function requirement(array $values = []): StaffQualificationRequirement
    {
        return StaffQualificationRequirement::create(['client_id' => $this->client->id, 'qualification_name' => 'Selected Client qualification',
            'qualification_type' => 'certification', 'description' => null, 'is_mandatory' => true, 'service_context_id' => $this->context->id,
            'hr_compliance_requirement_id' => null, ...$values]);
    }

    private function body(): array
    {
        return ['client_id' => $this->client->id, 'service_context_id' => $this->context->id, 'site_id' => $this->site->id,
            'user_id' => $this->worker->id, 'starts_at' => '2026-10-21T09:00:00+13:00', 'ends_at' => '2026-10-21T10:00:00+13:00',
            'shift_type' => 'standard', 'coverage_roles' => [], 'required_licence_class' => null, 'required_licence_endorsements' => []];
    }

    private function preview(array $values = [])
    {
        return $this->actingAs($this->actor)->getJson(route('operations.shifts.eligibility_preview', [...$this->body(), ...$values]))->assertSessionMissing('shift_result');
    }

    private function clientChecks(array $result): array
    {
        return array_values(array_filter($result['checked_rules'], fn ($check) => $check['rule'] === 'client_qualification'));
    }

    private function assertLegacyKeys(array $result): void
    {
        foreach (['is_eligible', 'is_allowed', 'blocked_reasons', 'warning_reasons', 'checked_rules', 'overrideable_warnings',
            'has_time_off', 'has_staff_conflict', 'has_compliance_block', 'has_tight_turnaround', 'compliance_warnings',
            'would_overfill_coverage', 'required_roles', 'matched_roles', 'missing_roles'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['staff_qualification_requirements', 'hr_compliance_requirements', 'staff_credentials', 'users', 'permission_user',
            'hr_employee_profiles', 'clients', 'service_contexts', 'app_settings', 'shifts', 'shift_tasks', 'shift_eligibility_overrides',
            'coverage_reservations', 'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => ($connection ?? DB::connection())->table($table)
            ->orderBy($table === 'permission_user' ? 'user_id' : 'id')->when($table === 'permission_user', fn ($query) => $query->orderBy('permission_id'))
            ->get()->map(fn ($row) => (array) $row)->all(), $tables));
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

    private function writer(): Connection
    {
        $name = 'preview_current_writer';
        config(['database.connections.'.$name => [...DB::connection()->getConfig(), 'name' => $name]]);
        DB::purge($name);
        $writer = DB::connection($name);
        $this->assertSame($name, $writer->getName());
        $this->assertTrue(OwnedTestDatabase::isOwnedBy($writer->getDatabaseName(), getmypid()));
        $this->assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame($writer->getDatabaseName(), $writer->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        $this->assertNotSame(DB::connection()->getPdo(), $writer->getPdo());
        $this->assertSame(0, $writer->transactionLevel());
        $this->assertFalse($writer->getPdo()->inTransaction());

        return $writer;
    }
}
