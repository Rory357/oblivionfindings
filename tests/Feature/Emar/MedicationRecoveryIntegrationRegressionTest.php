<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationError;
use App\Models\MedicationErrorAction;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationFollowupEvent;
use App\Models\MedicationSecondPersonConfirmation;
use App\Models\MedicationSiteSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Controlled\ControlledPolicy;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\ForgottenWitnessPinService;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Tasks\Providers\MedicationErrorActionProvider;
use App\Services\Tasks\Providers\MedicationErrorProvider;
use App\Services\Tasks\Providers\MedicationFollowupProvider;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Route-level regressions across P01/P07, P08a/P09 and P08b/Tasks. */
class MedicationRecoveryIntegrationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $manager;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc());
        Queue::fake();
        Cache::flush();
        $this->seed(RbacSeeder::class);
        config(['medications.witness_pin.forgotten_fallback_enabled' => true]);
        $this->site = Site::factory()->create([
            'name' => 'Synthetic recovery house', 'type' => 'house',
            'is_active' => true, 'archived' => false, 'archived_at' => null,
        ]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active',
        ]);
        $this->worker = $this->staff('support_worker');
        $this->permissions($this->worker, [
            'medications.view' => true, 'medications.administer.record' => true,
            'medications.administer.correct' => true, 'clients.viewAssigned' => true,
            'clients.viewAny' => false, 'medications.errors.manage' => false,
            'medications.audit.view' => false, 'medications.stock.update' => false,
            'medications.reports.export' => false, 'reports.viewAny' => false,
        ]);
        $this->client->supportWorkers()->attach($this->worker->id);
        $this->shift = $this->onShift($this->worker);
        $this->manager = $this->staff('team_lead');
        $this->permissions($this->manager, [
            'medications.view' => true, 'medications.administer.correct' => true,
            'medications.errors.manage' => true, 'clients.viewAny' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approval_route_retires_original_work_and_creates_only_accepted_replacement_work(): void
    {
        [$original, $old] = $this->prnSource();
        $originalFacts = $original->getRawOriginal();
        $originalHistory = $old->mapWithKeys(fn ($row) => [$row->id => $row->events()->sole()->getRawOriginal()]);
        $correction = $this->requestCorrection($original, 'given');
        $this->assertSame('pending', $correction->correction_status);
        $this->assertSame([$original->id], $this->effectiveIds($original));
        $this->assertSame(2, MedicationFollowup::query()->count());

        $this->actingAs($this->manager)->post(route('emar.corrections.approve', $correction))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('approved', $correction->fresh()->correction_status);
        $this->assertSame($this->manager->id, (int) $correction->fresh()->correction_approved_by);
        $this->assertSame([$correction->id], $this->effectiveIds($original));
        $this->assertSame($originalFacts, $original->fresh()->getRawOriginal());
        foreach ($old as $row) {
            $fresh = $row->fresh();
            $this->assertSame('done', $fresh->state);
            $this->assertNotNull($fresh->completed_at);
            $this->assertSame($row->revision + 1, $fresh->revision);
            $this->assertSame(1, $fresh->events()->where('action', 'source_retired')->count());
            $this->assertSame($originalHistory[$row->id], $fresh->events()->oldest('id')->firstOrFail()->getRawOriginal());
        }
        $replacement = MedicationFollowup::query()->where('administration_id', $correction->id)->get();
        $this->assertSame(['effect', 'partial'], $replacement->pluck('type')->sort()->values()->all());
        $this->assertTrue($replacement->every(fn ($row) => $row->state === 'open' && $row->completed_at === null));
        $this->assertTrue($replacement->firstWhere('type', 'effect')->due_at->equalTo($correction->effect_check_due_at));
        $this->assertSame(4, MedicationFollowup::query()->count());
        $visible = app(MedicationFollowupService::class)->visibleQuery($this->worker)->whereNull('completed_at')->pluck('id')->all();
        $this->assertEqualsCanonicalizing($replacement->pluck('id')->all(), $visible);
        $taskIds = array_map(fn ($task) => $task->id, app(MedicationFollowupProvider::class)->authorizedTasks($this->worker));
        $this->assertEqualsCanonicalizing($replacement->map(fn ($row) => 'medication-followup-'.$row->id)->all(), $taskIds);
    }

    public function test_not_given_approval_route_retires_the_effect_check_without_inventing_replacement_work(): void
    {
        [$original, $old] = $this->prnSource(false);
        $correction = $this->requestCorrection($original, 'not_given');
        $this->actingAs($this->manager)->post(route('emar.corrections.approve', $correction))
            ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame([$correction->id], $this->effectiveIds($original));
        $this->assertSame('given', $original->fresh()->status);
        $this->assertSame('done', $old->sole()->fresh()->state);
        $this->assertSame(1, $old->sole()->events()->where('action', 'source_retired')->count());
        $this->assertDatabaseCount('medication_followups', 1);
        $this->assertDatabaseMissing('medication_followups', ['administration_id' => $correction->id]);
        $this->assertCount(0, app(MedicationFollowupProvider::class)->authorizedTasks($this->worker));
    }

    public function test_audit_failure_rolls_back_actual_approval_and_all_source_work_then_retry_succeeds(): void
    {
        [$original, $old] = $this->prnSource();
        $correction = $this->requestCorrection($original, 'given');
        $doseFacts = ClientMedicationAdministration::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $workFacts = MedicationFollowup::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $historyFacts = MedicationFollowupEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $auditFacts = MedicationEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $headFacts = DB::table('medication_event_heads')->orderBy('site_id')->get()->toArray();
        $realRecorder = app(MedicationEventRecorder::class);
        $attempts = 0;
        $failAudit = function () use (&$attempts): never {
            $attempts++;
            throw new \RuntimeException('Synthetic correction audit failure');
        };
        $this->mock(MedicationEventRecorder::class, function ($mock) use ($failAudit) {
            $mock->shouldReceive('append')->andReturnUsing($failAudit);
            $mock->shouldReceive('appendMany')->andReturnUsing($failAudit);
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->manager)->post(route('emar.corrections.approve', $correction));
            $this->fail('The actual correction approval route committed without reaching the medication event recorder.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic correction audit failure', $exception->getMessage());
        } finally {
            $this->app->instance(MedicationEventRecorder::class, $realRecorder);
            $this->app['router']->getRoutes()->getByName('emar.corrections.approve')->flushController();
        }
        $this->assertSame(1, $attempts);
        $this->assertSame($doseFacts, ClientMedicationAdministration::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($workFacts, MedicationFollowup::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($historyFacts, MedicationFollowupEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($auditFacts, MedicationEvent::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertEquals($headFacts, DB::table('medication_event_heads')->orderBy('site_id')->get()->toArray());
        $this->assertSame([$original->id], $this->effectiveIds($original));

        $this->actingAs($this->manager)->post(route('emar.corrections.approve', $correction))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([$correction->id], $this->effectiveIds($original));
        foreach ($old as $row) {
            $this->assertSame(1, $row->events()->where('action', 'source_retired')->count());
        }
        $this->assertSame(2, MedicationFollowup::query()->where('administration_id', $correction->id)->count());
        $this->assertGreaterThan(count($auditFacts), MedicationEvent::query()->count());
    }

    public static function privacyAxes(): array
    {
        return [
            'own report, same-house person unreadable' => [false, true],
            'person readable, another reporter' => [true, false],
        ];
    }

    #[DataProvider('privacyAxes')]
    public function test_error_and_action_tasks_enforce_each_privacy_axis_and_preserve_manager_access(bool $readable, bool $ownReport): void
    {
        $other = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $this->client->service_context_id, 'status' => 'active',
        ]);
        $blockedPerson = $readable ? $this->client : $other;
        $allowed = $this->errorWithAction($this->client, $this->worker);
        $blocked = $this->errorWithAction($blockedPerson, $ownReport ? $this->worker : $this->manager);
        $this->assertSame($this->site->id, $blockedPerson->site_id);
        $this->assertSame($readable, Gate::forUser($this->worker)->allows('viewMedications', $blockedPerson));
        $this->assertSame($ownReport, (int) $blocked[0]->reported_by === (int) $this->worker->id);
        $this->assertFalse($this->worker->canDo('medications.errors.manage'));
        $this->assertFalse($this->worker->canDo('medications.audit.view'));
        $this->assertTrue(Gate::forUser($this->manager)->allows('viewMedications', $blockedPerson));
        $this->assertTrue($this->manager->canDo('medications.errors.manage'));

        foreach ([[MedicationErrorProvider::class, 'med_error', 0], [MedicationErrorActionProvider::class, 'med_error_action', 1]] as [$providerClass, $prefix, $index]) {
            $provider = app($providerClass);
            $tasks = $provider->authorizedTasks($this->worker);
            $this->assertSame([$prefix.'-'.$allowed[$index]->id], array_map(fn ($task) => $task->id, $tasks));
            $this->assertStringNotContainsString('PRIVATE', $tasks[0]->description);
            $this->assertSame([], $provider->authorizedTasks($this->worker, ['id' => $blocked[$index]->id]));
            $managerTasks = $provider->authorizedTasks($this->manager);
            $this->assertEqualsCanonicalizing([
                $prefix.'-'.$allowed[$index]->id, $prefix.'-'.$blocked[$index]->id,
            ], array_map(fn ($task) => $task->id, $managerTasks));
            $this->assertCount(1, $provider->authorizedTasks($this->manager, ['id' => $blocked[$index]->id]));
        }
        $this->actingAs($this->worker)->get('/emar/errors?error='.$blocked[0]->id)->assertNotFound();
    }

    public static function requiredWitnessCases(): array
    {
        return [
            'controlled, organisation requires witness' => [true, false, false],
            'controlled, explicit order wins over organisation and house off' => [true, true, true],
            'ordinary, explicit order wins over organisation and house off' => [false, true, true],
        ];
    }

    #[DataProvider('requiredWitnessCases')]
    public function test_working_ordinary_forgotten_pin_never_bypasses_controlled_or_explicit_order_witness(bool $controlled, bool $explicit, bool $policyOff): void
    {
        $this->permissions($this->worker, ['medications.controlled.record' => true, 'medications.controlled.view' => true]);
        $witness = $this->staff('support_worker');
        $this->permissions($witness, ['medications.administer.record' => true, 'medications.controlled.witness' => true]);
        $this->onShift($witness);
        foreach ([$this->worker, $witness] as $user) {
            MedicationCompetencyAssessment::query()->create([
                'user_id' => $user->id, 'assessor_id' => $this->manager->id, 'assessment_type' => 'annual',
                'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
                'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
                'can_administer_unsupervised' => true, 'can_witness_controlled' => true,
                'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => [],
            ]);
        }
        if ($policyOff) {
            AppSetting::query()->updateOrCreate(['key' => ControlledPolicy::WITNESS_REQUIRED], ['value' => 'off']);
            MedicationSiteSetting::query()->updateOrCreate([
                'site_id' => $this->site->id, 'key' => ControlledPolicy::HOUSE_WITNESS,
            ], ['value' => 'off']);
        }
        $ordinary = $this->scheduledOrder();
        $fallback = [
            'status' => 'given', 'amount_mode' => 'less', 'quantity_given' => 0.5,
            'amount_reason' => 'part_taken', 'witnessed_by' => $witness->id, 'second_person_pin_forgotten' => true,
        ];
        $this->requirements($ordinary)->assertOk()->assertJsonPath('second_person.forgotten_pin_allowed', true);
        $this->record($ordinary, $fallback)->assertOk();
        $ordinaryDose = ClientMedicationAdministration::query()->where('client_medication_id', $ordinary->id)->sole();
        $nomination = MedicationSecondPersonConfirmation::query()->sole();
        $this->assertSame('pending', $nomination->status);
        $this->assertSame($witness->id, (int) $nomination->nominated_user_id);
        $this->assertSame(RecordingContract::SECOND_NOT_VERIFIED, $ordinaryDose->second_person_status);
        $this->assertNull($ordinaryDose->witnessed_by);
        app(ForgottenWitnessPinService::class)->respond($witness, $nomination->id, true);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $ordinaryDose->fresh()->second_person_status);

        $required = $this->scheduledOrder(['controlled_drug' => $controlled, 'witness_required' => $explicit]);
        $stock = ClientMedicationStock::query()->create(['client_medication_id' => $required->id, 'on_hand' => 5, 'unit' => 'tablet']);
        $this->assertTrue(app(ControlledPolicy::class)->witnessRequired($required));
        $this->requirements($required)->assertOk()->assertJsonPath('second_person.kind', RecordingContract::SECOND_WITNESS)
            ->assertJsonPath('second_person.forgotten_pin_allowed', false);
        $eventCount = MedicationEvent::query()->count();
        $historyCount = MedicationFollowupEvent::query()->count();
        $this->record($required, [...$fallback, 'quantity_administered' => 0.5, 'cd_balance' => 4.5])
            ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        $this->assertDatabaseMissing('client_medication_administrations', ['client_medication_id' => $required->id]);
        $this->assertDatabaseMissing('medication_followups', ['client_medication_id' => $required->id]);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
        $this->assertSame('5.00', $stock->fresh()->on_hand);
        $this->assertSame($eventCount, MedicationEvent::query()->count());
        $this->assertSame($historyCount, MedicationFollowupEvent::query()->count());

        // The denial above must be about the fallback, not missing authority or stock.
        $this->record($required, [
            ...$fallback, 'second_person_pin_forgotten' => false, 'witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'quantity_administered' => 0.5, 'cd_balance' => 4.5,
        ])->assertOk();
        $verified = ClientMedicationAdministration::query()->where('client_medication_id', $required->id)->sole();
        $this->assertSame(RecordingContract::SECOND_WITNESS, $verified->second_person_kind);
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $verified->second_person_status);
        $this->assertSame($witness->id, (int) $verified->witnessed_by);
        $this->assertSame('witness_pin', $verified->witness_method);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
        if ($controlled) {
            $this->assertSame('4.50', $stock->fresh()->on_hand);
            $this->assertDatabaseCount('client_controlled_drug_entries', 1);
        }
    }

    private function prnSource(bool $partial = true): array
    {
        $order = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Synthetic recovery PRN', 'dosage' => '1 tablet',
            'frequency' => 'As needed', 'is_prn' => true, 'state' => 'active', 'active' => true,
        ]);
        $dose = ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'shift_id' => $this->shift->id,
            'service_context_id' => $this->client->service_context_id, 'administered_by' => $this->worker->id,
            'status' => 'given', 'administered_at' => now()->subMinutes(30), 'effect_check_due_at' => now()->addMinutes(30),
            'is_correction' => false, 'review_required' => $partial,
            'review_reason_key' => $partial ? RecordingContract::REVIEW_PARTIAL_DOSE_NOT_CONFIRMED : null,
            'review_reason' => $partial ? 'Synthetic partial dose needing review' : null,
        ]);
        DB::transaction(fn () => app(MedicationFollowupService::class)->syncAdministration($dose));

        return [$dose, MedicationFollowup::query()->where('administration_id', $dose->id)->orderBy('id')->get()];
    }

    private function requestCorrection(ClientMedicationAdministration $original, string $status): ClientMedicationAdministration
    {
        $this->actingAs($this->worker)->post(route('clients.mar.administrations.corrections.store', [$this->client, $original]), [
            'status' => $status, 'reason' => 'Synthetic outcome correction', 'correction_reason' => 'Synthetic record review',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        return ClientMedicationAdministration::query()->where('corrected_of_id', $original->id)->sole();
    }

    private function effectiveIds(ClientMedicationAdministration $original): array
    {
        return ClientMedicationAdministration::query()->effectiveClinicalEvidence()
            ->where(fn ($query) => $query->whereKey($original->id)->orWhere('corrected_of_id', $original->id))->pluck('id')->all();
    }

    private function errorWithAction(Client $person, User $reporter): array
    {
        $error = MedicationError::query()->create([
            'client_id' => $person->id, 'error_type' => 'documentation', 'severity' => 'minor',
            'description' => 'PRIVATE synthetic medicine narrative', 'reported_by' => $reporter->id,
            'reported_at' => now(), 'status' => 'reported', 'triage_due_at' => now()->addDay(),
        ]);
        $action = MedicationErrorAction::query()->create([
            'medication_error_id' => $error->id, 'description' => 'PRIVATE synthetic action narrative',
            'owner_id' => $this->worker->id, 'created_by' => $this->manager->id,
            'due_at' => now()->addDay(), 'created_at' => now(),
        ]);

        return [$error, $action];
    }

    private function staff(string $roleName): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->sole()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null,
        ]);

        return $user;
    }

    private function onShift(User $user): Shift
    {
        return Shift::factory()->create([
            'user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3), 'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null, 'status' => 'in_progress',
        ]);
    }

    private function permissions(User $user, array $permissions): void
    {
        $rows = Permission::query()->whereIn('key', array_keys($permissions))->get();
        $this->assertCount(count($permissions), $rows, 'Every permission used by the regression fixture must exist.');
        $user->permissionOverrides()->syncWithoutDetaching($rows->mapWithKeys(
            fn (Permission $permission) => [$permission->id => ['allowed' => $permissions[$permission->key]]],
        )->all());
        $user->unsetRelation('roles')->unsetRelation('permissionOverrides');
        Cache::flush();
    }

    private function scheduledOrder(array $attributes = []): ClientMedication
    {
        $clock = Carbon::getTestNow();
        Carbon::setTestNow(now('Pacific/Auckland')->startOfDay()->utc());
        try {
            return ClientMedication::query()->create([
                'client_id' => $this->client->id, 'name' => 'Synthetic recovery dose', 'dosage' => '1 tablet',
                'dose_amount' => 1, 'dose_unit' => 'tablet', 'dose_times' => ['09:30'], 'frequency' => 'Daily',
                'is_prn' => false, 'active' => true, 'state' => 'active', ...$attributes,
            ]);
        } finally {
            Carbon::setTestNow($clock);
        }
    }

    private function record(ClientMedication $order, array $fields)
    {
        return $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $order->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'client_request_uuid' => (string) Str::uuid(), ...$fields,
        ]);
    }

    private function requirements(ClientMedication $order)
    {
        return $this->actingAs($this->worker->fresh())->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
        ]));
    }
}
