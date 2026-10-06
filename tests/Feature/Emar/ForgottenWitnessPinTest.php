<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Emar\WorkerMedsController;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationRound;
use App\Models\MedicationSecondPersonConfirmation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Emar\MedsBoardPayloadService;
use App\Services\EnhancedMarService;
use App\Services\GuidedRoundService;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\ForgottenWitnessPinService;
use App\Services\Medication\Recording\RecordingContract;
use App\Services\Medication\SecondPersonConfirmationPayload;
use App\Services\Medication\WitnessPinService;
use App\Services\Medication\WitnessPinSettings;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** PIN-2 attestation: named colleague, exact deadline, current authority and safe replay. */
class ForgottenWitnessPinTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private Client $client;

    private ServiceContext $serviceContext;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        config(['medications.witness_pin.forgotten_fallback_enabled' => true]);

        $this->site = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'P01 contract',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'first_name' => 'Aroha',
            'last_name' => 'Ngata',
            'preferred_name' => 'Aroha',
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        $this->worker = $this->staffAt($this->site, 'Priya Shah');
        $this->onShift($this->worker, $this->client);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_fallback_is_truthfully_unavailable_until_the_consumer_is_installed(): void
    {
        config(['medications.witness_pin.forgotten_fallback_enabled' => false]);
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->requirements($order, '09:30')->assertOk()
            ->assertJsonPath('second_person.forgotten_pin_allowed', false)
            ->assertJsonPath('second_person.forgotten_pin_amount_allowed', false);
        $this->record($order, '09:30', $this->fallback($witness), true)
            ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        $this->assertDatabaseCount('medication_second_person_confirmations', 0);
    }

    public function test_a_named_colleague_confirms_in_their_own_login_and_replay_does_not_duplicate(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $uuid = (string) Str::uuid();
        $payload = $this->fallback($witness, ['client_request_uuid' => $uuid]);

        $this->record($order, '09:30', $payload, true)->assertOk()
            ->assertJsonPath('administration.second_person_status', RecordingContract::SECOND_NOT_VERIFIED)
            ->assertJsonPath('administration.witness_method', ForgottenWitnessPinService::METHOD)
            ->assertJsonPath('administration.second_person_confirmation.status', 'pending')
            ->assertJsonPath('administration.second_person_confirmation.nominated_name', $witness->name);
        $dose = ClientMedicationAdministration::query()->sole();
        $confirmation = MedicationSecondPersonConfirmation::query()->sole();
        $this->assertNull($dose->witnessed_by);
        $this->assertNull($dose->witnessed_at);
        $this->assertSame(ForgottenWitnessPinService::METHOD, $dose->witness_method);
        $this->assertSame(now()->addMinutes(30)->timestamp, $confirmation->due_at->timestamp);
        $this->assertNotEmpty($confirmation->eligibility_evidence['competency_assessment_id']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.pending')->count());
        $notification = $witness->notifications()->sole()->data;
        $this->assertSame('/medication-followups?open='.MedicationFollowup::query()->where('source_key', 'confirm:'.$confirmation->id)->sole()->id, $notification['action_url']);
        $this->assertStringNotContainsString($this->client->first_name, json_encode($notification));
        $this->assertStringNotContainsString($order->name, json_encode($notification));
        $this->assertStringNotContainsString(UserFactory::TEST_WITNESS_PIN, json_encode($notification));

        $service = app(ForgottenWitnessPinService::class);
        $this->assertCount(1, $service->pendingFor($witness));
        $this->assertCount(0, $service->pendingFor($this->worker));
        $this->record($order, '09:30', $payload, true)->assertOk();
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
        $this->assertDatabaseCount('notifications', 1);

        $answer = $service->respond($witness, $confirmation->id, true);
        $this->assertSame(['status' => 'confirmed', 'replayed' => false], $answer);
        $dose->refresh();
        $this->assertSame(RecordingContract::SECOND_VERIFIED, $dose->second_person_status);
        $this->assertSame($witness->id, $dose->witnessed_by);
        $this->assertSame(ForgottenWitnessPinService::CONFIRMED_METHOD, $dose->witness_method);
        $this->assertFalse($dose->review_required);
        $this->assertSame(['status' => 'confirmed', 'replayed' => true], $service->respond($witness, $confirmation->id, true));
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.second_person.confirmed')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.confirmed')->count());
        $followup = MedicationFollowup::query()->where('source_key', 'confirm:'.$confirmation->id)->sole();
        $this->assertSame('done', $followup->state);
        $this->assertSame($witness->id, $followup->completed_by);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'followup.source_completed')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.pending')->count());

        try {
            $service->respond($witness, $confirmation->id, false);
            $this->fail('A terminal answer cannot be overwritten.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('was_there', $error->errors());
        }
    }

    public function test_an_ordinary_rule_colleague_can_nominate_and_confirm_without_controlled_authority(): void
    {
        $witness = $this->witnessOnShift();
        $this->grant($witness, ['medications.view', 'medications.administer.record']);
        $this->deny($witness, ['medications.controlled.witness']);
        MedicationCompetencyAssessment::query()->where('user_id', $witness->id)->update([
            'controlled_drugs' => false, 'can_witness_controlled' => false,
        ]);
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        MedicationAdminRule::create([
            'site_id' => $this->site->id, 'match_type' => 'medicine_name', 'match_value' => $order->name,
            'requires_countersign' => true, 'required_observations' => [], 'active' => true,
            'created_by' => $this->worker->id,
        ]);
        $this->assertFalse($witness->fresh()->canDo('medications.controlled.witness'));
        $this->requirements($order, '09:30')->assertOk()
            ->assertJsonPath('second_person.kind', RecordingContract::SECOND_RULE)
            ->assertJsonPath('second_person.forgotten_pin_allowed', true)
            ->assertJsonPath('second_person.anyone_available', true);
        $uuid = (string) Str::uuid();
        $payload = $this->fallback($witness, [
            'amount_mode' => 'as_ordered', 'quantity_given' => null, 'amount_reason' => null,
            'client_request_uuid' => $uuid,
        ]);
        $this->record($order, '09:30', $payload, true)->assertOk()
            ->assertJsonPath('administration.second_person_confirmation.status', 'pending');
        $confirmation = MedicationSecondPersonConfirmation::query()->sole();
        $this->assertSame('medications.administer.record', $confirmation->eligibility_evidence['authority_permission']);
        $followup = MedicationFollowup::query()->where('source_key', 'confirm:'.$confirmation->id)->sole();
        $url = '/meds/confirmations/'.$confirmation->id;
        $this->actingAs($witness->fresh())->getJson('/medication-followups/'.$followup->id)->assertOk()
            ->assertJsonPath('can_complete', true);
        $this->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('second_person_confirmations', [[
                'id' => $confirmation->id, 'client_id' => $this->client->id,
                'client_name' => $this->client->full_name, 'medication_name' => $order->name,
                'due_at' => $confirmation->due_at->toIso8601String(), 'status' => 'pending',
                'followup_url' => '/medication-followups?open='.$followup->id,
            ]]));
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'pending');
        $this->postJson($url, ['was_there' => true])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson($url, ['was_there' => true])->assertOk()->assertJsonPath('replayed', true);
        $this->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('second_person_confirmations', 0));
        $this->record($order, '09:30', $payload, true)->assertOk()
            ->assertJsonPath('administration.second_person_confirmation.status', 'confirmed');
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.confirmed')->count());
        $this->assertSame('done', $followup->fresh()->state);
    }

    public function test_ordinary_nomination_rechecks_administration_authority_and_actual_presence(): void
    {
        $witness = $this->witnessOnShift();
        $this->deny($witness, ['medications.controlled.witness', 'medications.administer.record']);
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $payload = $this->fallback($witness);
        $this->record($order, '09:30', $payload, true)->assertNotFound();
        $this->grant($witness, ['medications.administer.record']);
        Shift::query()->where('user_id', $witness->id)->where('site_id', $this->site->id)->update([
            'status' => 'completed', 'actual_ends_at' => now()->subMinute(),
        ]);
        $this->record($order, '09:30', $payload, true)->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('medication_second_person_confirmations', 0);
        $this->assertDatabaseCount('medication_followups', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, MedicationEvent::query()->where('kind', 'second_person.pending')->count());
    }

    public function test_meds_today_confirmation_duties_remain_named_current_and_truthful_at_expiry(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $this->actingAs($this->worker)->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('second_person_confirmations', 0));
        $this->travel(30)->minutes();
        $this->actingAs($witness)->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('second_person_confirmations', 1)
            ->where('second_person_confirmations.0.status', 'expired')
            ->missing('second_person_confirmations.0.eligibility_evidence')
            ->missing('second_person_confirmations.0.nominated_user_id')
            ->missing('second_person_confirmations.0.pin_hash'));
        $this->assertSame('pending', $confirmation->refresh()->status);
        $this->assertNull($dose->refresh()->witnessed_by);
        $this->assertFalse($dose->review_required);
        $this->deny($witness, ['medications.administer.record']);
        $this->actingAs($witness->fresh())->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('second_person_confirmations', 0));
        $this->grant($witness, ['medications.administer.record']);
        $this->client->forceFill(['site_id' => Site::factory()->create(['is_active' => true])->id])->save();
        $this->actingAs($witness->fresh())->get('/meds/today')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('second_person_confirmations', 0));
    }

    public function test_meds_today_confirmation_duties_batch_effective_evidence_and_followup_links(): void
    {
        $witness = $this->witnessOnShift();
        $project = new \ReflectionMethod(WorkerMedsController::class, 'secondPersonConfirmations');
        $controller = app(WorkerMedsController::class);
        $firstCount = null;
        foreach (range(1, 3) as $index) {
            $order = $this->order(['09:30'], ['name' => 'Confirmation '.$index, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
            $this->record($order, '09:30', $this->fallback($witness), true)->assertOk();
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $rows = $project->invoke($controller, $witness->fresh(), now());
                $queries = collect(DB::getQueryLog())->pluck('query');
                $this->assertCount($index, $rows);
                $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query, 'from `medication_second_person_confirmations`'))->count());
                $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query, 'from `medication_followups`'))->count());
                $this->assertSame(2, $queries->filter(fn ($query) => str_contains($query, 'from `client_medication_administrations`'))->count());
                $firstCount ??= $queries->count();
                $this->assertLessThanOrEqual($firstCount, $queries->count());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }
    }

    public function test_a_dispute_flags_one_review_on_the_original_dose(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $service = app(ForgottenWitnessPinService::class);
        $service->respond($witness, $confirmation->id, false);
        $service->respond($witness, $confirmation->id, false);

        $this->assertTrue($dose->refresh()->review_required);
        $this->assertSame(ForgottenWitnessPinService::REVIEW_DISPUTED, $dose->review_reason_key);
        $this->assertSame('disputed', $dose->second_person_status);
        $this->assertNull($dose->witnessed_by);
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.second_person.disputed')->count());
        DB::transaction(fn () => app(MedicationFollowupService::class)->syncAdministration($dose->refresh()));
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'disputed')->count());
        $this->assertSame(2, MedicationFollowup::query()->count());
    }

    public function test_expiry_at_the_exact_deadline_is_durable_and_idempotent(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $service = app(ForgottenWitnessPinService::class);
        $this->travel(29)->minutes();
        $this->assertSame(0, $service->expireDue());
        $this->travel(1)->minutes();
        $this->assertSame(1, $service->expireDue());
        $this->assertSame(0, $service->expireDue());
        $this->assertTrue($dose->refresh()->review_required);
        $this->assertSame(ForgottenWitnessPinService::REVIEW_EXPIRED, $dose->review_reason_key);
        $this->assertSame(['status' => 'expired', 'replayed' => true], $service->respond($witness, $confirmation->id, true));
        $this->assertNull($dose->refresh()->witnessed_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.second_person.expired')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.expired')->count());
    }

    public function test_a_late_answer_commits_expiry_before_returning_the_expired_result(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $this->travel(30)->minutes();

        $this->assertSame(['status' => 'expired', 'replayed' => false],
            app(ForgottenWitnessPinService::class)->respond($witness, $confirmation->id, true));
        $this->assertSame('expired', $confirmation->refresh()->status);
        $this->assertTrue($dose->refresh()->review_required);
    }

    public function test_controlled_and_other_witness_required_doses_never_use_fallback(): void
    {
        $witness = $this->witnessOnShift();
        foreach ([['controlled_drug' => true], ['witness_required' => true]] as $flags) {
            $order = $this->order(['09:30'], [...$flags, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
            $this->record($order, '09:30', $this->fallback($witness), true)
                ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_second_person_confirmations', 0);
    }

    public function test_fallback_cannot_bypass_locked_reset_expired_unset_or_actor_attempt_budgets(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $pin = UserWitnessPin::query()->where('user_id', $witness->id)->sole();
        $defaults = ['must_change' => false, 'locked_until' => null, 'set_at' => now()];
        app(WitnessPinSettings::class)->save([WitnessPinSettings::RENEWAL_MONTHS => '1']);
        foreach ([
            ['locked_until' => now()->addMinutes(15)],
            ['must_change' => true],
            ['set_at' => now()->subMonths(2)],
        ] as $state) {
            $pin->forceFill([...$defaults, ...$state])->save();
            $this->record($order, '09:30', $this->fallback($witness), true)
                ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        }
        $pin->forceFill($defaults)->save();
        $key = app(WitnessPinService::class)->attemptBudgetKey((int) $this->worker->id, $pin);
        foreach (range(1, 5) as $unused) {
            RateLimiter::hit($key, 900);
        }
        $this->record($order, '09:30', $this->fallback($witness), true)
            ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        RateLimiter::clear($key);
        $pin->delete();
        $this->record($order, '09:30', $this->fallback($witness), true)
            ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_a_forged_foreign_site_and_the_recorder_are_not_eligible_nominees(): void
    {
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $foreign = $this->staffAt(Site::factory()->create(['is_active' => true]), 'Elsewhere');
        $this->record($order, '09:30', $this->fallback($foreign), true)->assertNotFound();
        $this->record($order, '09:30', $this->fallback($this->worker), true)
            ->assertStatus(422)->assertJsonValidationErrors('witnessed_by');
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_only_the_nominee_can_answer_and_current_person_and_staff_authority_still_apply(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $service = app(ForgottenWitnessPinService::class);
        try {
            $service->respond($this->worker, $confirmation->id, true);
            $this->fail('The recorder cannot answer as their colleague.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->client->forceFill(['site_id' => Site::factory()->create(['is_active' => true])->id])->save();
        $this->assertCount(0, $service->pendingFor($witness));
        try {
            $service->respond($witness, $confirmation->id, true);
            $this->fail('A moved person is no longer readable at the old site.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
        $this->assertSame('pending', $confirmation->refresh()->status);
        $this->assertNull($dose->refresh()->witnessed_by);
    }

    public function test_a_revoked_permission_or_departed_employee_cannot_confirm(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $this->deny($witness, ['medications.administer.record']);
        try {
            app(ForgottenWitnessPinService::class)->respond($witness, $confirmation->id, true);
            $this->fail('Revoked authority must apply to a stored nomination.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->grant($witness, ['medications.administer.record']);
        HrEmployeeProfile::query()->where('user_id', $witness->id)->update(['is_active' => false]);
        try {
            app(ForgottenWitnessPinService::class)->respond($witness->fresh(), $confirmation->id, true);
            $this->fail('A departed employee must not confirm a nomination.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->assertSame('pending', $confirmation->refresh()->status);
    }

    public function test_as_needed_recording_keeps_the_fallback_field(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order([], ['is_prn' => true, 'max_per_day' => 4, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->actingAs($this->worker)->postJson('/meds/today/prn', [
            'client_medication_id' => $order->id,
            'reason' => 'Pain',
            'administered_at' => now()->toIso8601String(),
            ...$this->fallback($witness),
        ])->assertOk()
            ->assertJsonPath('administration.second_person_status', RecordingContract::SECOND_NOT_VERIFIED)
            ->assertJsonPath('administration.witness_method', ForgottenWitnessPinService::METHOD)
            ->assertJsonPath('administration.second_person_confirmation.status', 'pending')
            ->assertJsonPath('administration.second_person_confirmation.nominated_name', $witness->name);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
        $this->assertSame(RecordingContract::SECOND_NOT_VERIFIED,
            ClientMedicationAdministration::query()->sole()->second_person_status);
    }

    public function test_a_missing_protected_pin_key_is_not_a_fallback_or_an_attempt(): void
    {
        $witness = $this->witnessOnShift();
        $pin = UserWitnessPin::query()->where('user_id', $witness->id)->sole();
        $pin->forceFill(['hash_version' => WitnessPinService::HASH_PEPPERED])->save();
        config(['medications.witness_pin.pepper' => null]);
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->record($order, '09:30', $this->fallback($witness), true)
            ->assertStatus(422)->assertJsonValidationErrors('witness_credential');
        $key = app(WitnessPinService::class)->attemptBudgetKey((int) $this->worker->id, $pin);
        $this->assertSame(0, RateLimiter::attempts($key));
        $this->assertSame(0, $pin->refresh()->failed_attempts);
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_audit_chain_failure_rolls_back_the_dose_nomination_receipt_bell_and_followup(): void
    {
        $this->withoutExceptionHandling();
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $recorder = \Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic audit failure.'));
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        try {
            $this->record($order, '09:30', $this->fallback($witness, ['client_request_uuid' => (string) Str::uuid()]), true);
            $this->fail('A clinical write must not commit without its audit chain.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure.', $error->getMessage());
        }
        foreach (['client_medication_administrations', 'medication_second_person_confirmations',
            'medication_idempotency_results', 'notifications', 'medication_followups', 'medication_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_expiry_resolves_the_canonical_workflow_for_a_deleted_person_without_exposing_it(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $this->client->delete();
        $this->travel(30)->minutes();
        $service = app(ForgottenWitnessPinService::class);
        $this->assertSame(1, $service->expireDue());
        $this->assertSame(0, $service->expireDue());
        $this->assertSame('expired', $confirmation->refresh()->status);
        $this->assertSame('done', MedicationFollowup::query()->where('source_key', 'confirm:'.$confirmation->id)->sole()->state);
        $this->assertSame(0, app(MedicationFollowupService::class)->visibleQuery($witness)->count());
        $this->assertNull(MedicationEvent::query()->where('kind', 'second_person.expired')->sole()->client_id);
    }

    public function test_the_source_http_consumer_only_answers_in_the_named_colleagues_login(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $url = '/meds/confirmations/'.$confirmation->id;
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $this->actingAs($this->worker)->postJson($url, ['was_there' => true])->assertNotFound();
        $this->actingAs($witness)->getJson($url)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('person_name', 'Aroha Ngata')
            ->assertJsonPath('status', 'pending')
            ->assertJsonMissingPath('eligibility_evidence')
            ->assertJsonMissingPath('pin_hash');
        $this->postJson($url, ['was_there' => true])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['status' => 'confirmed', 'replayed' => false]);
        $this->postJson($url, ['was_there' => true])->assertOk()
            ->assertJson(['status' => 'confirmed', 'replayed' => true]);
        $this->postJson($url, ['was_there' => false])->assertUnprocessable()
            ->assertJsonValidationErrors('was_there');
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->assertSame($witness->id, $dose->refresh()->witnessed_by);
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.confirmed')->count());
        $this->assertSame($witness->id, AuditLog::query()->where('action', 'medications.second_person.confirmed')->sole()->user_id);
    }

    public function test_source_http_reads_and_answers_recheck_current_person_and_witness_authority(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $url = '/meds/confirmations/'.$confirmation->id;
        $this->deny($witness, ['medications.administer.record']);
        $this->actingAs($witness->fresh())->getJson($url)->assertForbidden();
        $this->postJson($url, ['was_there' => true])->assertForbidden();
        $this->grant($witness, ['medications.administer.record']);
        $this->client->forceFill(['site_id' => Site::factory()->create(['is_active' => true])->id])->save();
        $this->actingAs($witness->fresh())->getJson($url)->assertNotFound();
        $this->postJson($url, ['was_there' => true])->assertNotFound();
        $this->assertNull($dose->refresh()->witnessed_by);
    }

    public function test_the_scheduled_expiry_command_runs_at_the_exact_deadline_and_replays_safely(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $this->travel(29)->minutes();
        $this->artisan('emar:expire-second-person-confirmations')->assertSuccessful();
        $this->assertSame('pending', $confirmation->refresh()->status);
        $this->travel(1)->minutes();
        $this->artisan('emar:expire-second-person-confirmations')->assertSuccessful();
        $this->artisan('emar:expire-second-person-confirmations')->assertSuccessful();
        $this->assertSame('expired', $confirmation->refresh()->status);
        $this->assertTrue($dose->refresh()->review_required);
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'disputed')->count());
        $this->assertSame(1, MedicationEvent::query()->where('kind', 'second_person.expired')->count());
    }

    #[DataProvider('priorReviewOutcomes')]
    public function test_terminal_attestation_preserves_an_existing_clinical_review(?bool $wasThere, string $status): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $flaggedAt = now()->subMinute();
        $dose->forceFill([
            'review_required' => true,
            'review_reason_key' => 'partial_taken',
            'review_reason' => 'Existing clinical concern — only part taken.',
            'review_flagged_at' => $flaggedAt,
            'review_flagged_by' => $this->worker->id,
        ])->save();

        $service = app(ForgottenWitnessPinService::class);
        if ($wasThere === null) {
            $this->travel(30)->minutes();
            $this->assertSame(1, $service->expireDue());
            $this->assertSame(0, $service->expireDue());
        } else {
            $this->assertSame(['status' => $status, 'replayed' => false],
                $service->respond($witness, $confirmation->id, $wasThere));
        }

        $dose->refresh();
        $this->assertTrue($dose->review_required);
        $this->assertSame('partial_taken', $dose->review_reason_key);
        $this->assertSame('Existing clinical concern — only part taken.', $dose->review_reason);
        $this->assertSame($flaggedAt->timestamp, $dose->review_flagged_at->timestamp);
        $this->assertSame($this->worker->id, $dose->review_flagged_by);
        $this->assertSame($status, $confirmation->refresh()->status);
        $this->assertSame($wasThere === true ? RecordingContract::SECOND_VERIFIED : $status, $dose->second_person_status);
        $this->assertSame($wasThere === true ? 0 : 1, MedicationFollowup::query()->where('type', 'disputed')->count());

        DB::transaction(fn () => app(MedicationFollowupService::class)->syncAdministration($dose->refresh()));
        $this->assertSame(1, MedicationFollowup::query()->where('source_key', 'dose-review:'.$dose->id)->count(),
            'The prior clinical concern retains its own canonical follow-up.');
        $this->assertSame($wasThere === true ? 0 : 1, MedicationFollowup::query()->where('type', 'disputed')->count());
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public static function priorReviewOutcomes(): array
    {
        return [
            'confirmed' => [true, 'confirmed'],
            'disputed' => [false, 'disputed'],
            'expired' => [null, 'expired'],
        ];
    }

    /** @return array<string, mixed> */
    public function test_actual_named_confirmation_consumer_resolves_and_replays_one_followup(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $url = '/meds/confirmations/'.$confirmation->id;
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $this->actingAs($witness)->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('id', $confirmation->id)->assertJsonPath('person_name', $this->client->full_name)
            ->assertJsonPath('medication_name', $dose->medication->name)->assertJsonPath('status', 'pending');
        $this->actingAs($witness)->postJson($url, ['was_there' => true])->assertOk()->assertJson(['status' => 'confirmed', 'replayed' => false]);
        $this->actingAs($witness)->postJson($url, ['was_there' => true])->assertOk()->assertJson(['status' => 'confirmed', 'replayed' => true]);
        $this->actingAs($witness)->postJson($url, ['was_there' => false])->assertUnprocessable()->assertJsonValidationErrors('was_there');
        $this->assertSame($witness->id, $dose->fresh()->witnessed_by);
        $this->assertSame('verified', $dose->fresh()->second_person_status);
        $this->assertSame('done', MedicationFollowup::where('source_key', 'confirm:'.$confirmation->id)->sole()->state);
        $this->assertSame(1, MedicationEvent::where('kind', 'second_person.confirmed')->count());
    }

    public function test_actual_consumer_dispute_and_exact_deadline_never_fabricate_a_witness(): void
    {
        [$dose,$confirmation,$witness] = $this->nominate();
        $this->actingAs($witness)->postJson('/meds/confirmations/'.$confirmation->id, ['was_there' => false])->assertOk()->assertJsonPath('status', 'disputed');
        $this->assertNull($dose->fresh()->witnessed_by);
        $this->assertTrue($dose->fresh()->review_required);
        $this->assertSame('done', MedicationFollowup::where('source_key', 'confirm:'.$confirmation->id)->sole()->state);
    }

    public function test_expiry_command_closes_due_nomination_before_any_late_http_answer(): void
    {
        [$dose,$confirmation,$witness] = $this->nominate();
        Carbon::setTestNow($confirmation->due_at->copy()->utc());
        $this->artisan('emar:expire-second-person-confirmations')->assertExitCode(0);
        $this->assertSame('expired', $confirmation->fresh()->status);
        $this->assertSame('expired', $dose->fresh()->second_person_status);
        $this->assertNull($dose->fresh()->witnessed_by);
        $this->assertSame('done', MedicationFollowup::where('source_key', 'confirm:'.$confirmation->id)->sole()->state);
        $this->actingAs($witness)->postJson('/meds/confirmations/'.$confirmation->id, ['was_there' => true])->assertOk()->assertJson(['status' => 'expired', 'replayed' => true]);
        $this->artisan('emar:expire-second-person-confirmations')->assertExitCode(0);
        $this->assertSame(1, MedicationEvent::where('kind', 'second_person.expired')->count());
    }

    public function test_smaller_amount_fallback_is_advertised_only_for_non_witness_doses(): void
    {
        $this->grant($this->worker, ['medications.controlled.view']);
        $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->requirements($order, '09:30')->assertOk()
            ->assertJsonPath('second_person.kind', null)
            ->assertJsonPath('second_person.forgotten_pin_allowed', false)
            ->assertJsonPath('second_person.forgotten_pin_amount_allowed', true)
            ->assertJsonPath('second_person.confirm_within_minutes', 30);

        foreach ([['controlled_drug' => true], ['witness_required' => true]] as $flags) {
            $witnessOrder = $this->order(['09:30'], [...$flags, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
            $this->requirements($witnessOrder, '09:30')->assertOk()
                ->assertJsonPath('second_person.forgotten_pin_allowed', false)
                ->assertJsonPath('second_person.forgotten_pin_amount_allowed', false);
        }
    }

    public function test_pending_confirmation_is_projected_without_private_evidence_and_reads_do_not_expire_it(): void
    {
        [$dose, $confirmation, $witness] = $this->nominate();
        $expected = $this->confirmationPayload($confirmation, $witness);
        $this->assertConfirmationReaders($dose, $expected, RecordingContract::SECOND_NOT_VERIFIED, ForgottenWitnessPinService::METHOD);

        $loaded = $dose->fresh()->load(SecondPersonConfirmationPayload::RELATION);
        $this->assertArrayNotHasKey('second_person_confirmation', $loaded->toArray());
        $this->assertArrayNotHasKey('secondPersonConfirmation', $loaded->toArray());
        $safe = app(SecondPersonConfirmationPayload::class)->forAdministration($loaded);
        $this->assertSame(['id', 'status', 'nominated_name', 'due_at'], array_keys($safe['second_person_confirmation']));
        $this->assertStringNotContainsString(UserFactory::TEST_WITNESS_PIN, json_encode($safe));

        $this->travel(30)->minutes();
        $this->assertSame($expected, app(SecondPersonConfirmationPayload::class)->forAdministration($dose->fresh())['second_person_confirmation']);
        $this->assertSame('pending', $confirmation->refresh()->status, 'Only the canonical answer/expiry writer creates a terminal outcome.');
        $this->assertFalse($dose->refresh()->review_required);
    }

    #[DataProvider('priorReviewOutcomes')]
    public function test_terminal_projection_and_same_uuid_retries_use_current_saved_state(?bool $wasThere, string $status): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $payload = $this->fallback($witness, ['client_request_uuid' => (string) Str::uuid()]);
        $this->record($order, '09:30', $payload, true)->assertOk();
        $dose = ClientMedicationAdministration::query()->sole();
        $confirmation = MedicationSecondPersonConfirmation::query()->sole();

        $prn = $this->order([], ['is_prn' => true, 'max_per_day' => 4, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $prnPayload = [
            'client_medication_id' => $prn->id,
            'reason' => 'Pain',
            'administered_at' => now()->toIso8601String(),
            ...$this->fallback($witness, ['client_request_uuid' => (string) Str::uuid()]),
        ];
        $prnResponse = $this->actingAs($this->worker)->postJson('/meds/today/prn', $prnPayload)->assertOk();
        $prnDose = ClientMedicationAdministration::query()->findOrFail($prnResponse->json('administration.id'));
        $prnConfirmation = MedicationSecondPersonConfirmation::query()->where('administration_id', $prnDose->id)->sole();

        $service = app(ForgottenWitnessPinService::class);
        if ($wasThere === null) {
            $this->travel(30)->minutes();
            $this->assertSame(2, $service->expireDue());
        } else {
            $service->respond($witness, $confirmation->id, $wasThere);
            $service->respond($witness, $prnConfirmation->id, $wasThere);
        }
        $secondStatus = $wasThere === true ? RecordingContract::SECOND_VERIFIED : $status;
        $method = $wasThere === true ? ForgottenWitnessPinService::CONFIRMED_METHOD : ForgottenWitnessPinService::METHOD;
        $expected = $this->confirmationPayload($confirmation->refresh(), $witness);
        $expectedPrn = $this->confirmationPayload($prnConfirmation->refresh(), $witness);
        $this->record($order, '09:30', $payload, true)->assertOk()
            ->assertJsonPath('administration.id', $dose->id)
            ->assertJsonPath('administration.second_person_status', $secondStatus)
            ->assertJsonPath('administration.witness_method', $method)
            ->assertJsonPath('administration.second_person_confirmation', $expected);
        $this->actingAs($this->worker)->postJson('/meds/today/prn', $prnPayload)->assertOk()
            ->assertJsonPath('administration.id', $prnDose->id)
            ->assertJsonPath('administration.second_person_status', $secondStatus)
            ->assertJsonPath('administration.witness_method', $method)
            ->assertJsonPath('administration.second_person_confirmation', $expectedPrn);
        $this->assertDatabaseCount('client_medication_administrations', 2);
        $this->assertDatabaseCount('medication_second_person_confirmations', 2);
        $this->assertSame($wasThere === true ? $witness->id : null, $dose->refresh()->witnessed_by);
        $this->assertSame($wasThere === true ? $witness->id : null, $prnDose->refresh()->witnessed_by);
        $this->assertConfirmationReaders($dose, $expected, $secondStatus, $method);
        $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('prn_recorded_today.0.second_person_confirmation', $expectedPrn));
        $this->actingAs($this->worker)->get('/emar/prn?client_id='.$this->client->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('emar/record/hub')
                ->where('page.data.0.second_person_confirmation', $expectedPrn));
        $this->legacyRead('/emar/prn?client_id='.$this->client->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('administrations.0.second_person_confirmation', $expectedPrn)
                ->where('history.data.0.second_person_confirmation', $expectedPrn));
    }

    public function test_confirmation_list_queries_are_batched_as_more_doses_are_added(): void
    {
        $witness = $this->witnessOnShift();
        for ($i = 0; $i < 3; $i++) {
            $order = $this->order(['09:30'], ['name' => 'Dose '.$i, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
            $this->record($order, '09:30', $this->fallback($witness), true)->assertOk();
            if ($i === 0 || $i === 2) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    $board = app(MedsBoardPayloadService::class);
                    $doses = $board->administrationsForDay([$this->client->id], Carbon::now('Pacific/Auckland')->startOfDay());
                    $rows = $doses->map(fn ($dose) => $board->recordedPayload($dose, 'Pacific/Auckland'));
                    $queries = collect(DB::getQueryLog())->pluck('query');
                    $this->assertCount($i + 1, $rows);
                    $this->assertSame(1, $queries->filter(fn ($query) => str_contains($query, 'from `medication_second_person_confirmations`'))->count());
                    $this->assertSame(2, $queries->filter(fn ($query) => str_contains($query, 'from `users`'))->count());
                    $this->assertSame(['pending'], $rows->pluck('second_person_confirmation.status')->unique()->values()->all());
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
        }
    }

    public function test_normal_pin_evidence_never_claims_own_login_confirmation(): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $fields = $this->fallback($witness, ['witness_credential' => UserFactory::TEST_WITNESS_PIN]);
        unset($fields['second_person_pin_forgotten']);
        $this->record($order, '09:30', $fields, true)->assertOk()
            ->assertJsonPath('administration.second_person_status', RecordingContract::SECOND_VERIFIED)
            ->assertJsonPath('administration.witness_method', WitnessPinService::METHOD)
            ->assertJsonPath('administration.second_person_confirmation', null);
        $this->assertDatabaseCount('medication_second_person_confirmations', 0);
    }

    #[DataProvider('priorReviewOutcomes')]
    public function test_open_and_completed_round_items_and_cells_retain_confirmation_outcomes(?bool $wasThere, string $status): void
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $round = MedicationRound::query()->create([
            'site_id' => $this->site->id, 'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning round', 'scheduled_time' => '09:30:00', 'window_minutes' => 60,
            'round_date' => '2026-04-30', 'status' => 'in_progress',
            'assigned_to' => $this->worker->id, 'started_by' => $this->worker->id, 'started_at' => now(),
            'total_medications' => 1,
        ]);
        $payload = $this->fallback($witness, ['medication_round_id' => $round->id, 'client_request_uuid' => (string) Str::uuid()]);
        $this->record($order, '09:30', $payload, true)->assertOk();
        $confirmation = MedicationSecondPersonConfirmation::query()->sole();
        $rounds = app(GuidedRoundService::class);
        $this->assertSame($this->confirmationPayload($confirmation, $witness), $rounds->items($round)[0]['administration']['second_person_confirmation']);
        $this->assertSame('pending', $rounds->cells($round)[0]['second_person_confirmation']['status']);

        if ($wasThere === null) {
            $this->travel(30)->minutes();
            $this->assertSame(1, app(ForgottenWitnessPinService::class)->expireDue());
        } else {
            app(ForgottenWitnessPinService::class)->respond($witness, $confirmation->id, $wasThere);
        }
        $expected = $this->confirmationPayload($confirmation->refresh(), $witness);
        $secondStatus = $wasThere === true ? RecordingContract::SECOND_VERIFIED : $status;
        $this->assertSame($expected, $rounds->items($round)[0]['administration']['second_person_confirmation']);
        $this->assertSame($secondStatus, $rounds->cells($round)[0]['second_person_status']);
        $this->record($order, '09:30', $payload, true)->assertOk()
            ->assertJsonPath('administration.second_person_confirmation', $expected);
        $round->forceFill(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $this->worker->id])->save();
        $this->assertSame($expected, $rounds->items($round)[0]['administration']['second_person_confirmation']);
        $this->assertSame($expected, $rounds->cells($round)[0]['second_person_confirmation']);
        $this->record($order, '09:30', $payload, true)->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertDatabaseCount('medication_second_person_confirmations', 1);
    }

    private function confirmationPayload(MedicationSecondPersonConfirmation $confirmation, User $witness): array
    {
        return ['id' => $confirmation->id, 'status' => $confirmation->status,
            'nominated_name' => $witness->name, 'due_at' => $confirmation->due_at->toIso8601String()];
    }

    private function assertConfirmationReaders(ClientMedicationAdministration $dose, array $expected, string $status, string $method): void
    {
        $this->grant($this->worker, ['medications.view']);
        $this->actingAs($this->worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schedule.0.recorded.second_person_confirmation', $expected)
                ->where('schedule.0.recorded.second_person_status', $status)
                ->where('schedule.0.recorded.witness_method', $method));
        $this->legacyRead('/emar/mar?client_id='.$this->client->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('schedule.0.recorded.second_person_confirmation', $expected));
        $this->actingAs($this->worker)->getJson('/emar/clients/'.$this->client->id.'/day')->assertOk()
            ->assertJsonPath('medicines.0.cells.09:30.0.recorded.second_person_confirmation', $expected);
        $this->actingAs($this->worker)->getJson('/emar/clients/'.$this->client->id.'/record/doses/'.$dose->id)->assertOk()
            ->assertJsonPath('dose.second_person_confirmation', $expected)
            ->assertJsonPath('chain.0.second_person_confirmation', $expected);

        $legacy = app(EnhancedMarService::class)->build($this->client->fresh(), Carbon::now('Pacific/Auckland')->startOfDay());
        $this->assertSame($expected, $legacy['scheduled'][0]['administration']['second_person_confirmation']);
        $this->assertSame($expected, collect($legacy['history'])->firstWhere('id', $dose->id)['second_person_confirmation']);
        $activity = app(MedsBoardPayloadService::class)->activityPage([$this->client->id], Carbon::now('Pacific/Auckland'), false);
        $this->assertSame($expected, collect($activity->items())->firstWhere('id', $dose->id)['second_person_confirmation']);
    }

    private function legacyRead(string $url)
    {
        $mode = config('medications.person_record');
        config(['medications.person_record' => 'legacy']);
        try {
            return $this->actingAs($this->worker)->get($url);
        } finally {
            config(['medications.person_record' => $mode]);
        }
    }

    private function fallback(User $witness, array $extra = []): array
    {
        return [
            'status' => 'given',
            'amount_mode' => 'less',
            'quantity_given' => 0.5,
            'amount_reason' => 'part_taken',
            'witnessed_by' => $witness->id,
            'second_person_pin_forgotten' => true,
            ...$extra,
        ];
    }

    /** @return array{ClientMedicationAdministration, MedicationSecondPersonConfirmation, User} */
    private function nominate(): array
    {
        $witness = $this->witnessOnShift();
        $order = $this->order(['09:30'], ['dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->record($order, '09:30', $this->fallback($witness), true)->assertOk();

        return [ClientMedicationAdministration::query()->sole(),
            MedicationSecondPersonConfirmation::query()->sole(), $witness];
    }

    // ─── helpers ─────────────────────────────────────────────

    /**
     * @param  string  $time  "HH:MM" today (30 April), or "YYYY-MM-DD HH:MM"
     * @param  array<string, mixed>  $fields
     */
    private function record(ClientMedication $order, string $time, array $fields, bool $json = false, ?User $as = null)
    {
        $payload = array_filter([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse(strlen($time) > 5 ? $time : '2026-04-30 '.$time, 'Pacific/Auckland')->toIso8601String(),
            ...$fields,
        ], fn ($value) => $value !== null);

        $request = $this->actingAs($as ?? $this->worker)->from('/meds/today');

        return $json
            ? $request->postJson('/meds/today/record', $payload)
            : $request->post('/meds/today/record', $payload);
    }

    private function requirements(ClientMedication $order, string $time, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->worker)->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id,
            'scheduled_for' => Carbon::parse('2026-04-30 '.$time, 'Pacific/Auckland')->toIso8601String(),
        ]));
    }

    /** @param  array<string, mixed>  $overrides */
    private function order(array $doseTimes, array $overrides = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $order = ClientMedication::query()->create(array_merge([
            'client_id' => $this->client->id,
            'name' => 'Morning tablets',
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'controlled_drug' => false,
            'high_risk' => false,
            'witness_required' => false,
            'approval_status' => 'verified',
            'active' => true,
            'state' => 'active',
        ], $overrides));
        Carbon::setTestNow($now);

        return $order;
    }

    private function staffAt(Site $site, string $name): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'name' => $name]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->grant($user, ['medications.administer.record']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
            'controlled_drugs' => true,
            'can_witness_controlled' => true,
        ]);

        return $user;
    }

    private function onShift(User $user, Client $client): void
    {
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $client->site_id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $user->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);
    }

    private function witnessOnShift(string $name = 'Jordan Tipene'): User
    {
        $witness = $this->staffAt($this->site, $name);
        $this->grant($witness, ['medications.controlled.witness']);
        $this->onShift($witness, $this->client);

        return $witness;
    }

    /** @param  list<string>  $keys */
    private function deny(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
                ->all(),
        );
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Cache::flush();
    }

    /** @param  list<string>  $keys */
    private function grant(User $user, array $keys): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $keys)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
