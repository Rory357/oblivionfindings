<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationEvent;
use App\Models\MedicationFollowup;
use App\Models\MedicationSecondPersonConfirmation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\ForgottenWitnessPinService;
use App\Services\Medication\Recording\RecordingContract;
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
            ->assertJsonPath('second_person.forgotten_pin_allowed', false);
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
            ->assertJsonPath('administration.second_person_status', RecordingContract::SECOND_NOT_VERIFIED);
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
        $this->deny($witness, ['medications.controlled.witness']);
        try {
            app(ForgottenWitnessPinService::class)->respond($witness, $confirmation->id, true);
            $this->fail('Revoked authority must apply to a stored nomination.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->grant($witness, ['medications.controlled.witness']);
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
        ])->assertOk();
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
        $this->deny($witness, ['medications.controlled.witness']);
        $this->actingAs($witness->fresh())->getJson($url)->assertForbidden();
        $this->postJson($url, ['was_there' => true])->assertForbidden();
        $this->grant($witness, ['medications.controlled.witness']);
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
