<?php

namespace Tests\Feature\Rostering;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Domain\Shifts\Planning\ShiftPlanningIntent;
use App\Http\Controllers\Operations\QualificationMatchController;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftEligibilityOverride;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\Eligibility\AssignmentEligibilityGateway;
use App\Services\Eligibility\EligibilityResult;
use App\Services\Eligibility\Rules\ClientQualificationRule;
use App\Services\Eligibility\WorkforceEligibilitySources;
use App\Services\ShiftStaffEligibilityService;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\OwnedTestDatabase;
use Tests\TestCase;

/** Client mapping, current assignment applicability and actual committed CRUD outcomes. */
class WorkforceClientQualificationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private ServiceContext $context;

    private User $actor;

    private User $worker;

    private HrComplianceRequirement $canonical;

    private bool $committed = false;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 00:00:00', 'UTC'));
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => null, 'status' => 'active']);
        $this->context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'type' => 'home_support', 'is_active' => true]);
        $this->actor = $this->person(['rostering.viewAny', 'qualifications.create', 'qualifications.edit', 'qualifications.delete',
            'shifts.create', 'shifts.update', 'shifts.viewAny', 'shifts.manageAny', 'shifts.overrideEligibility']);
        $this->worker = $this->person();
        $this->canonical = HrComplianceRequirement::factory()->create(['check_type' => 'credential', 'is_active' => true,
            'hard_stop' => false, 'validity_months' => null, 'reference_id' => null]);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('wf32_client_writer');
            if ($this->committed && DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction()) {
                DB::beginTransaction();
            }
        } finally {
            Carbon::setTestNow();
            parent::tearDown();
        }
    }

    public function test_actual_root_crud_preserves_omissions_clears_explicit_nulls_and_confirms_only_saved_values(): void
    {
        $reader = $this->person(['rostering.viewAny']);
        $this->commitFixtures();
        $this->withManager(function () use ($reader): void {
            $this->actingAs($this->actor)->post($this->url('store'), $this->body())->assertSessionHasNoErrors();
            $row = StaffQualificationRequirement::where('client_id', $this->client->id)->sole();
            $receipt = $this->receipt('created', $row);
            $this->assertSame($receipt, session('qualification_requirement_result'));
            $this->assertSource($row);
            $this->get($this->url('index'))->assertInertia(fn (Assert $page) => $page->where('flash.qualification_requirement_result', fn ($value) => $value->all() == $receipt));
            $this->actingAs($reader)->withSession(['qualification_requirement_result' => $receipt])->get($this->url('index'))
                ->assertInertia(fn (Assert $page) => $page->where('flash.qualification_requirement_result', null));
            $state = $this->state();
            $queue = $this->queue();
            $this->actingAs($this->actor)->withSession(['qualification_requirement_result' => $receipt])->postJson($this->url('store'), [])
                ->assertUnprocessable()->assertJsonValidationErrors('client_id')->assertSessionMissing('qualification_requirement_result');
            $this->assertSame($state, $this->state());
            $this->assertSame($queue, $this->queue());
            $version = $this->intent($row)->source_version;
            $this->put($this->url('update', $row), ['client_id' => PHP_INT_MAX, 'qualification_name' => 'Renamed guidance'])->assertSessionHasNoErrors();
            $row->refresh();
            $this->assertSame($this->receipt('updated', $row), session('qualification_requirement_result'));
            $this->assertSame($this->client->id, $row->client_id, 'The canonical Client cannot be retargeted by this update.');
            $this->assertSame($this->canonical->id, $row->hr_compliance_requirement_id);
            $this->assertSame($this->context->id, $row->service_context_id);
            $this->assertSame('Recorded qualification guidance', $row->description);
            $this->assertSame($version, $this->intent($row)->source_version, 'Narrative-only changes do not stage eligibility work.');
            $this->assertSame($queue, $this->queue());
            $this->put($this->url('update', $row), ['description' => null, 'service_context_id' => null, 'hr_compliance_requirement_id' => null])->assertSessionHasNoErrors();
            $row->refresh();
            $this->assertNull($row->description);
            $this->assertNull($row->service_context_id);
            $this->assertNull($row->hr_compliance_requirement_id);
            $this->assertSame('certification', $row->qualification_type);
            $this->assertSame('Renamed guidance', $row->qualification_name);
            $this->assertSame($version + 1, $this->intent($row)->source_version);
            $this->assertSame($this->receipt('updated', $row), session('qualification_requirement_result'));
            $this->assertSource($row);
            $this->delete($this->url('destroy', $row))->assertSessionHasNoErrors()
                ->assertSessionHas('qualification_requirement_result', ['action' => 'deleted', 'actor_id' => $this->actor->id, 'requirement_id' => $row->id, 'values' => null]);
            $this->assertFalse(StaffQualificationRequirement::whereKey($row->id)->exists());
            $this->assertSource($row, true);
            $this->assertSame(['workforce.qualification.created', 'workforce.qualification.updated', 'workforce.qualification.updated', 'workforce.qualification.deleted'],
                AuditLog::where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)
                    ->where('action', 'like', 'workforce.qualification.%')->orderBy('id')->pluck('action')->all());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        });
    }

    public function test_read_contract_withholds_foreign_patterns_and_exposes_only_explicit_active_supported_catalogue(): void
    {
        $visible = $this->requirement();
        $foreign = Client::factory()->create(['site_id' => Site::factory()->create()->id]);
        $hidden = $this->requirement(['client_id' => $foreign->id]);
        $wrongContext = ServiceContext::factory()->create(['site_id' => $foreign->site_id]);
        $this->requirement(['service_context_id' => $wrongContext->id]);
        $inactive = HrComplianceRequirement::factory()->create(['check_type' => 'credential', 'is_active' => false]);
        $unsupported = HrComplianceRequirement::factory()->create(['check_type' => 'unsupported', 'is_active' => true]);
        $reader = $this->person(['rostering.viewAny', 'reports.viewAny']);
        $before = $this->state();
        $queue = $this->queue();
        $this->actingAs($reader)->get($this->url('index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('requirements.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$visible->id])
            ->where('requirements.data.0.mapping.requirement_id', $this->canonical->id)
            ->where('requirements.data.0.service_context.id', $this->context->id)
            ->where('can', ['create' => false, 'edit' => false, 'delete' => false])
            ->where('editableClients', [])->where('serviceContexts', [])
            ->where('complianceRequirements', fn ($rows) => ! collect($rows)->pluck('id')->contains($inactive->id)
                && ! collect($rows)->pluck('id')->contains($unsupported->id)));
        $this->getJson($this->url('index').'?client_id='.$foreign->id)->assertOk();
        $this->putJson($this->url('update', $hidden), ['description' => 'Reader attempt'])->assertForbidden();
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    #[DataProvider('mutationBoundaries')]
    public function test_exact_write_grant_and_existing_site_boundary_remain_required(string $kind, int $status): void
    {
        $outside = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $outside->id]);
        $row = $this->requirement(['client_id' => $client->id, 'service_context_id' => null]);
        $keys = ['rostering.viewAny', 'reports.viewAny'];
        if ($kind !== 'read only') {
            $keys[] = 'qualifications.edit';
        }
        if ($kind === 'manager') {
            $keys[] = 'shifts.manageAny';
        }
        $actor = $this->person($keys);
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($kind, $status, $actor, $row): void {
            $response = $this->actingAs($actor)->putJson($this->url('update', $row), ['description' => 'Lawful changed guidance']);
            if ($kind === 'manager') {
                $response->assertRedirect()->assertSessionHasNoErrors();
                $this->assertSame('Lawful changed guidance', $row->fresh()->description);
                $this->assertSame($actor->id, session('qualification_requirement_result.actor_id'));
            } else {
                $response->assertStatus($status);
            }
        });
        if ($kind !== 'manager') {
            $this->assertSame($before, $this->state());
        }
        $this->assertSame($queue, $this->queue());
    }

    public static function mutationBoundaries(): array
    {
        return ['read grant alone' => ['read only', 403], 'reports is not mutation Site bypass' => ['reports', 404], 'existing manager bypass' => ['manager', 302]];
    }

    public function test_context_and_mapping_validation_rejects_bad_references_without_clearing_existing_mapping(): void
    {
        $row = $this->requirement();
        $outside = ServiceContext::factory()->create(['site_id' => Site::factory()->create()->id]);
        $inactive = HrComplianceRequirement::factory()->create(['is_active' => false, 'check_type' => 'credential']);
        $unsupported = HrComplianceRequirement::factory()->create(['is_active' => true, 'check_type' => 'unsupported']);
        $this->commitFixtures();
        $this->withManager(function () use ($row, $outside, $inactive, $unsupported): void {
            $before = $this->state();
            $queue = $this->queue();
            $this->actingAs($this->actor)->putJson($this->url('update', $row), ['service_context_id' => $outside->id])->assertUnprocessable();
            foreach ([$inactive->id, $unsupported->id, PHP_INT_MAX] as $id) {
                $this->putJson($this->url('update', $row), ['hr_compliance_requirement_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('hr_compliance_requirement_id');
            }
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queue());
            $this->assertNull(session('qualification_requirement_result'));
        });
    }

    #[DataProvider('nestedOutcomes')]
    public function test_nested_crud_has_no_committed_receipt_and_outer_rollback_removes_every_aggregate_effect(bool $commit): void
    {
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($commit, $before, $queue): void {
            if (! $commit) {
                $controller = app(QualificationMatchController::class);
                $request = Request::create($this->url('store'), 'POST');
                $request->setUserResolver(fn () => $this->actor);
                $request->setLaravelSession(session()->driver());
                session()->put('qualification_requirement_result', ['action' => 'prior']);
                $pdo = DB::connection()->getPdo();
                $pdo->beginTransaction();
                try {
                    $this->assertSame(0, DB::transactionLevel());
                    $this->assertTrue($pdo->inTransaction());
                    $this->assertFalse((new ReflectionMethod($controller, 'beginQualificationReceipt'))->invoke($controller, $request));
                    $this->assertFalse((new ReflectionMethod($controller, 'qualificationReceiptIsRoot'))->invoke($controller, true));
                    $this->assertNull(session('qualification_requirement_result'));
                } finally {
                    $pdo->rollBack();
                }
            }
            DB::beginTransaction();
            $this->actingAs($this->actor)->withSession(['qualification_requirement_result' => ['action' => 'prior']])
                ->post($this->url('store'), $this->body())->assertSessionHasNoErrors()->assertSessionMissing('qualification_requirement_result');
            $this->assertSame($queue, $this->queue());
            $this->assertSame(1, DB::transactionLevel());
            $this->assertTrue(DB::connection()->getPdo()->inTransaction());
            $commit ? DB::commit() : DB::rollBack();
            $this->assertNull(session('qualification_requirement_result'));
            if ($commit) {
                $row = StaffQualificationRequirement::where('client_id', $this->client->id)->sole();
                $this->assertSource($row);
            } else {
                $this->assertSame($before, $this->state());
                $this->assertSame($queue, $this->queue());
            }
        });
    }

    public static function nestedOutcomes(): array
    {
        return ['commit' => [true], 'rollback' => [false]];
    }

    #[DataProvider('vetoes')]
    public function test_refused_or_altered_save_delete_audit_or_source_intent_rolls_back_all_work(string $model, string $event, string $mode): void
    {
        $row = $this->requirement();
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($row, $model, $event, $mode): void {
            $this->withListener($model, $event, function ($record) use ($mode) {
                if ($mode === 'alter') {
                    $record->description = 'Unexpected observer value';

                    return null;
                }

                return false;
            }, function () use ($row, $event): void {
                $response = $this->actingAs($this->actor);
                ($event === 'deleting' ? $response->deleteJson($this->url('destroy', $row))
                    : $response->putJson($this->url('update', $row), ['description' => 'Requested guidance', 'hr_compliance_requirement_id' => null]))
                    ->assertConflict()->assertSessionMissing('qualification_requirement_result');
            });
        });
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function vetoes(): array
    {
        return ['save refusal' => [StaffQualificationRequirement::class, 'saving', 'veto'],
            'altered saved value' => [StaffQualificationRequirement::class, 'saving', 'alter'],
            'delete refusal' => [StaffQualificationRequirement::class, 'deleting', 'veto'],
            'mandatory audit refusal' => [AuditLog::class, 'creating', 'veto'],
            'source intent refusal' => [WorkforceEligibilityRecheck::class, 'saving', 'veto']];
    }

    #[DataProvider('currentDenials')]
    public function test_current_account_grant_site_source_and_catalogue_evidence_wins_over_primed_rr(string $change, int $status): void
    {
        $actor = $this->person(['rostering.viewAny', 'qualifications.edit']);
        $row = $this->requirement();
        $foreign = Site::factory()->create();
        $permission = Permission::where('key', 'qualifications.edit')->sole();
        $this->commitFixtures();
        $writer = $this->writer();
        $this->withManager(function () use ($actor, $row, $foreign, $permission, $change, $status, $writer): void {
            DB::beginTransaction();
            $actor->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
            $this->assertTrue($actor->canDo('qualifications.edit'));
            $old = $this->state();
            $writer->transaction(function () use ($actor, $foreign, $permission, $change, $writer): void {
                match ($change) {
                    'approval' => $writer->table('users')->where('id', $actor->id)->update(['approved_at' => null]),
                    'grant' => $writer->table('permission_user')->where('user_id', $actor->id)->where('permission_id', $permission->id)->update(['allowed' => false]),
                    'profile' => $writer->table('hr_employee_profiles')->where('user_id', $actor->id)->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => '[]']),
                    'client' => $writer->table('clients')->where('id', $this->client->id)->update(['site_id' => $foreign->id]),
                    'context' => $writer->table('service_contexts')->where('id', $this->context->id)->update(['site_id' => $foreign->id]),
                    'mapping' => $writer->table('hr_compliance_requirements')->where('id', $this->canonical->id)->update(['is_active' => false]),
                };
            });
            $this->assertSame($old, $this->state());
            $current = $this->state($writer);
            $this->assertNotSame($old, $current);
            $queue = $this->queue();
            $request = Request::create($this->url('update', $row), 'PUT', ['description' => 'Forbidden stale draft', 'hr_compliance_requirement_id' => $this->canonical->id]);
            $request->setUserResolver(fn () => $actor);
            $request->setLaravelSession(session()->driver());
            try {
                app(QualificationMatchController::class)->update($request, $row->id);
                $this->fail('Current qualification evidence must deny the stale writer.');
            } catch (HttpException $exception) {
                $this->assertSame($status, $exception->getStatusCode());
            } catch (ValidationException $exception) {
                $this->assertSame($status, $exception->status);
                $this->assertArrayHasKey('hr_compliance_requirement_id', $exception->errors());
            }
            $this->assertSame($current, $this->state($writer));
            $this->assertSame($queue, $this->queue());
            $this->assertNull(session('qualification_requirement_result'));
            DB::rollBack();
        });
    }

    public static function currentDenials(): array
    {
        return ['account approval' => ['approval', 403], 'exact edit grant' => ['grant', 403], 'current profile Site' => ['profile', 404],
            'current Client Site' => ['client', 404], 'current old Context' => ['context', 404], 'configured mapping inactive' => ['mapping', 422]];
    }

    public function test_only_exact_client_general_or_exact_context_mandatory_requirements_apply_without_name_guessing(): void
    {
        $general = $this->requirement(['service_context_id' => null, 'hr_compliance_requirement_id' => null]);
        $exact = $this->requirement(['hr_compliance_requirement_id' => null]);
        $different = ServiceContext::factory()->create(['site_id' => $this->site->id]);
        $this->requirement(['service_context_id' => $different->id, 'hr_compliance_requirement_id' => null]);
        $this->requirement(['is_mandatory' => false, 'hr_compliance_requirement_id' => null]);
        $this->requirement(['client_id' => null, 'hr_compliance_requirement_id' => null]);
        $this->requirement(['client_id' => Client::factory()->create(['site_id' => $this->site->id])->id, 'hr_compliance_requirement_id' => null]);
        // A matching label is not an explicit mapping to this recorded credential.
        StaffCredential::create(['user_id' => $this->worker->id, 'type' => $general->qualification_name, 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01']);
        $duty = $this->duty();
        $before = $this->state();
        $queue = $this->queue();
        $checks = app(ClientQualificationRule::class)->evaluateAll($duty, $this->worker, true);
        $this->assertSame([$general->id, $exact->id], array_column($checks, 'requirement_id'));
        foreach ($checks as $check) {
            $this->assertSame('unmapped', $check['qualification_status']);
            $this->assertSame('warning', $check['severity']);
            $this->assertTrue($check['overrideable']);
            $this->assertTrue($check['requires_assignment_acknowledgement']);
        }
        $duty->client_id = null;
        $this->assertSame([], app(ClientQualificationRule::class)->evaluateAll($duty, $this->worker, true));
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public function test_structural_requirement_changes_recheck_both_old_and_new_clients_without_fingerprinting_private_guidance(): void
    {
        $row = $this->requirement();
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $initial = $this->intent($row);
        $fingerprint = $initial->source_fingerprint;
        $version = $initial->source_version;
        $queue = $this->queue();
        $row->update(['description' => 'Private narrative is not an eligibility input', 'qualification_name' => 'Display label']);
        $this->assertSame($fingerprint, $this->intent($row)->source_fingerprint);
        $this->assertSame($version, $this->intent($row)->source_version);
        $this->assertSame($queue, $this->queue());
        $row->update(['client_id' => $other->id, 'hr_compliance_requirement_id' => null, 'is_mandatory' => false]);
        $intent = $this->intent($row);
        $this->assertSame($version + 1, $intent->source_version);
        $this->assertNotSame($fingerprint, $intent->source_fingerprint);
        $this->assertContains($this->client->id, $intent->client_ids);
        $this->assertContains($other->id, $intent->client_ids);
        $this->assertStringNotContainsString('Private narrative', json_encode($intent->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_check_shift_reports_actual_mapped_evidence_optional_warning_and_governed_unmapped_severity(): void
    {
        $mapped = $this->requirement();
        $unmapped = $this->requirement(['qualification_name' => 'Unmapped mandatory', 'hr_compliance_requirement_id' => null]);
        $optional = $this->requirement(['qualification_name' => 'Optional unresolved', 'hr_compliance_requirement_id' => null, 'is_mandatory' => false]);
        StaffCredential::create(['user_id' => $this->worker->id, 'type' => $this->canonical->code, 'issued_at' => '2026-01-01', 'expires_at' => '2027-01-01']);
        $duty = $this->duty();
        $before = $this->state();
        $queue = $this->queue();
        $this->actingAs($this->actor)->get(route('operations.qualifications.check', $duty))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('allMandatoryMet', false)->where('hasWarnings', true)->where('hasBlocks', false)
            ->where('unmappedMandatoryMode', 'warn')->has('results', 3)
            ->where('results', function ($rows) use ($mapped, $unmapped, $optional) {
                $byId = collect($rows)->keyBy('requirement.id');

                return $byId[$mapped->id]['met'] === true && $byId[$mapped->id]['status'] === 'met'
                    && $byId[$unmapped->id]['requires_acknowledgement'] === true
                    && $byId[$optional->id]['requires_acknowledgement'] === false;
            }));
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
        AppSetting::create(['key' => HrEligibilityRuleSettings::KEY, 'value' => ['version' => 1,
            'values' => ['unmapped_mandatory_qualification' => 'block', 'house_qualification_approach' => 'per_requirement']]]);
        $this->get(route('operations.qualifications.check', $duty))->assertInertia(fn (Assert $page) => $page
            ->where('hasBlocks', true)->where('hasWarnings', true)->where('allMandatoryMet', false)->where('unmappedMandatoryMode', 'block'));
    }

    #[DataProvider('governedCreates')]
    public function test_current_client_warning_governs_only_marked_create_and_never_claims_a_rejected_shift_saved(string $kind, int $status, ?string $reason): void
    {
        $this->requirement(['hr_compliance_requirement_id' => $kind === 'mapped missing' ? $this->canonical->id : null]);
        if ($kind === 'block') {
            AppSetting::create(['key' => HrEligibilityRuleSettings::KEY, 'value' => ['version' => 1, 'values' => ['unmapped_mandatory_qualification' => 'block', 'house_qualification_approach' => 'per_requirement']]]);
        }
        if ($kind === 'no grant') {
            $permission = Permission::where('key', 'shifts.overrideEligibility')->sole();
            $this->actor->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
            $this->actor = $this->actor->fresh();
        }
        $this->bindClientEvaluator();
        $body = $this->shiftBody();
        if (in_array($kind, ['missing reason', 'manager', 'no grant', 'block', 'mapped missing'], true)) {
            $body['override_acknowledged'] = true;
        }
        if (in_array($kind, ['manager', 'no grant', 'block', 'mapped missing'], true)) {
            $body['override_reason'] = 'Reviewed unresolved Client mapping for this duty';
        }
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($kind, $status, $reason, $body): void {
            $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1');
            $response = $status === 302 ? $this->post(route('operations.shifts.store'), $body) : $this->postJson(route('operations.shifts.store'), $body);
            $response->assertStatus($status);
            if ($reason !== null) {
                $response->assertSessionHasNoErrors();
                $receipt = session('shift_result');
                $this->assertSame(['action' => 'create', 'actor_id' => $this->actor->id, 'shift_id' => null, 'scope' => 'single',
                    'source' => null, 'outcome' => 'not_saved', 'changed' => false, 'reason' => $reason,
                    'values_hash' => ShiftPlanningIntent::hash(ShiftPlanningIntent::normalize($body))], $receipt);
                $this->assertNotEmpty(session('eligibility_result.warning_reasons'));
            } elseif ($kind === 'manager') {
                $response->assertSessionHasNoErrors();
                $row = Shift::where('client_id', $this->client->id)->sole();
                $this->assertSame($this->worker->id, $row->user_id);
                $this->assertSame('saved', session('shift_result.outcome'));
                $this->assertSame($row->id, session('shift_result.shift_id'));
                $override = ShiftEligibilityOverride::where('shift_id', $row->id)->sole();
                $this->assertSame($this->actor->id, $override->overridden_by);
                $this->assertSame(['client_qualification'], $override->rules_overridden);
                $this->assertSame($body['override_reason'], $override->override_reason);
            } else {
                $this->assertNull(session('shift_result'));
            }
        });
        if ($kind !== 'manager') {
            $this->assertSame($before, $this->state());
            $this->assertSame($queue, $this->queue());
        }
    }

    public static function governedCreates(): array
    {
        return ['review first' => ['unacknowledged', 302, 'eligibility_warning'], 'reason required' => ['missing reason', 302, 'override_reason_required'],
            'authorised reason' => ['manager', 302, null], 'no override grant' => ['no grant', 403, null],
            'configured block' => ['block', 422, null], 'mapped missing remains block' => ['mapped missing', 422, null]];
    }

    public function test_unmarked_generic_create_warning_remains_allowed_and_receipt_preserves_actual_warning(): void
    {
        $this->bindClientEvaluator(true);
        $this->commitFixtures();
        $this->withManager(function (): void {
            $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1')->post(route('operations.shifts.store'), $this->shiftBody())->assertSessionHasNoErrors();
            $row = Shift::where('client_id', $this->client->id)->sole();
            $this->assertSame($row->id, session('shift_result.shift_id'));
            $this->assertSame(['Existing advisory warning'], session('shift_result.assignment_warnings'));
            $this->assertFalse(ShiftEligibilityOverride::where('shift_id', $row->id)->exists());
        });
    }

    #[DataProvider('changedSource')]
    public function test_assigned_client_or_context_only_edit_rechecks_new_applicability_without_retiming(string $field): void
    {
        $otherClient = Client::factory()->create(['site_id' => $this->site->id]);
        $otherContext = ServiceContext::factory()->create(['site_id' => $this->site->id, 'type' => 'home_support', 'is_active' => true]);
        $this->requirement(['client_id' => $field === 'client_id' ? $otherClient->id : $this->client->id,
            'service_context_id' => $field === 'service_context_id' ? $otherContext->id : null, 'hr_compliance_requirement_id' => $this->canonical->id]);
        $row = $this->duty(['service_context_id' => $field === 'service_context_id' ? $this->context->id : null]);
        $this->bindClientEvaluator();
        $this->commitFixtures();
        $before = $this->state();
        $queue = $this->queue();
        $body = $this->shiftBody();
        $body['service_context_id'] = $row->service_context_id;
        $body[$field] = $field === 'client_id' ? $otherClient->id : $otherContext->id;
        $this->withManager(function () use ($row, $body): void {
            $this->actingAs($this->actor)->withHeader('X-Shift-Result', 'committed-v1')->putJson(route('operations.shifts.update', $row), $body)
                ->assertUnprocessable()->assertJsonValidationErrors('user_id')->assertSessionMissing('shift_result');
        });
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    public static function changedSource(): array
    {
        return ['Client only' => ['client_id'], 'Context only' => ['service_context_id']];
    }

    public function test_current_mapping_lock_conflict_translates_only_native_nowait_and_preserves_all_work(): void
    {
        $row = $this->requirement();
        $this->commitFixtures();
        $writer = $this->writer();
        $before = $this->state();
        $queue = $this->queue();
        $this->withManager(function () use ($row, $writer): void {
            $writer->beginTransaction();
            $writer->table('hr_compliance_requirements')->where('id', $this->canonical->id)->lockForUpdate()->first();
            try {
                $this->actingAs($this->actor)->putJson($this->url('update', $row), ['hr_compliance_requirement_id' => $this->canonical->id])
                    ->assertStatus(503)->assertJsonValidationErrors('qualification_name')->assertSessionMissing('qualification_requirement_result');
            } finally {
                $writer->rollBack();
            }
        });
        $this->assertSame($before, $this->state());
        $this->assertSame($queue, $this->queue());
    }

    private function bindClientEvaluator(bool $generic = false): void
    {
        $rule = app(ClientQualificationRule::class);
        $this->mock(ShiftStaffEligibilityService::class, function ($mock) use ($rule, $generic): void {
            $mock->shouldReceive('evaluate')->andReturnUsing(function (Shift $shift, User $worker) use ($rule, $generic) {
                $checks = $generic ? [['rule' => 'existing_advisory', 'passed' => false, 'severity' => 'warning', 'overrideable' => true,
                    'message' => 'Existing advisory warning']] : $rule->evaluateAll($shift, $worker, DB::transactionLevel() > 0);

                return EligibilityResult::fromChecks($checks);
            });
        });
        $this->assertSame(AssignmentEligibilityGateway::class, get_class(app(AssignmentEligibilityGateway::class)));
    }

    private function person(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        $role = Role::create(['name' => 'wf32-client-'.Str::uuid(), 'label' => 'WF32 Client fixture', 'level' => 10, 'type' => 'custom']);
        $user->roles()->attach($role);
        foreach ($keys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'workforce', 'module' => 'Operations']);
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null, 'created_by' => $user->id, 'updated_by' => $user->id]);

        return $user->fresh();
    }

    private function body(): array
    {
        return ['client_id' => $this->client->id, 'qualification_name' => 'Explicit Client qualification', 'qualification_type' => 'certification',
            'is_mandatory' => true, 'description' => 'Recorded qualification guidance', 'service_context_id' => $this->context->id,
            'hr_compliance_requirement_id' => $this->canonical->id];
    }

    private function requirement(array $values = []): StaffQualificationRequirement
    {
        return StaffQualificationRequirement::create([...$this->body(), ...$values]);
    }

    private function shiftBody(): array
    {
        return ['client_id' => $this->client->id, 'service_context_id' => $this->context->id, 'user_id' => $this->worker->id,
            'starts_at' => '2026-10-11T19:00:07Z', 'ends_at' => '2026-10-11T23:00:12Z', 'status' => 'scheduled', 'shift_type' => 'standard',
            'location' => 'Synthetic qualification duty', 'notes' => null, 'is_sleepover' => false, 'is_on_call' => false,
            'is_lone_worker' => false, 'expected_break_minutes' => 0, 'coverage_roles' => []];
    }

    private function duty(array $values = []): Shift
    {
        return Shift::factory()->create([...$this->shiftBody(), 'site_id' => $this->site->id, 'created_by' => $this->actor->id,
            'required_licence_class' => null, 'required_licence_endorsements' => [], ...$values]);
    }

    private function url(string $action, ?StaffQualificationRequirement $row = null): string
    {
        return route('operations.qualifications.'.$action, $row ? ['requirement' => $row->id] : []);
    }

    private function receipt(string $action, StaffQualificationRequirement $row): array
    {
        return ['action' => $action, 'actor_id' => $this->actor->id, 'requirement_id' => $row->id, 'values' => [
            'client_id' => $row->client_id, 'qualification_name' => $row->qualification_name, 'qualification_type' => $row->qualification_type,
            'is_mandatory' => (bool) $row->is_mandatory, 'description' => $row->description,
            'service_context_id' => $row->service_context_id, 'hr_compliance_requirement_id' => $row->hr_compliance_requirement_id]];
    }

    private function intent(StaffQualificationRequirement $row): WorkforceEligibilityRecheck
    {
        return WorkforceEligibilityRecheck::where('source_type', $row->getTable())->where('source_id', $row->id)->sole();
    }

    private function assertSource(StaffQualificationRequirement $row, bool $deleted = false): void
    {
        $intent = $this->intent($row);
        $source = app(WorkforceEligibilitySources::class)->describe($row, $deleted);
        $this->assertSame($source['fingerprint'], $intent->source_fingerprint);
        $this->assertContains($this->client->id, $intent->client_ids);
        $this->assertGreaterThan(0, $intent->source_version);
        $this->assertSame('pending', $intent->status);
    }

    private function state(?Connection $connection = null): array
    {
        $tables = ['staff_qualification_requirements', 'hr_compliance_requirements', 'staff_credentials', 'users', 'permission_user',
            'hr_employee_profiles', 'clients', 'service_contexts', 'shifts', 'shift_tasks', 'shift_eligibility_overrides', 'coverage_reservations',
            'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

        return array_combine($tables, array_map(fn ($table) => ($connection ?? DB::connection())->table($table)->orderBy($table === 'permission_user' ? 'user_id' : 'id')
            ->when($table === 'permission_user', fn ($query) => $query->orderBy('permission_id'))->get()->map(fn ($row) => (array) $row)->all(), $tables));
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
        $name = 'wf32_client_writer';
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
