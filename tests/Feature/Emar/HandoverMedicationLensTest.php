<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ShiftHandoverService;
use App\Services\ShiftTimelineService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Coverage for the eMAR medication lens layered onto shift handovers:
 *  - the live "Medications this shift" snapshot endpoint (window-scoped),
 *  - controlled-drug two-person count persistence, and
 *  - optimistic-concurrency (version) edit-locking on the shared draft.
 */
class HandoverMedicationLensTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected User $witness;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create(['type' => 'house', 'name' => 'Tui House', 'is_active' => true]);
        $this->worker = $this->makeUser('admin', [
            'medications.view', 'handovers.create', 'handovers.viewAny',
            'shifts.update', 'shifts.manageAny', 'clients.update',
            'medications.controlled.view', 'medications.controlled.record',
        ]);
        $this->witness = $this->makeUser('support_worker', [
            'shifts.update', 'medications.controlled.witness',
        ]);
        foreach ([$this->worker, $this->witness] as $staff) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $staff->id,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => null,
            ]);
        }

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Residential', 'type' => 'residential', 'is_active' => true,
        ]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);

        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->witness->id,
            'assessor_id' => $this->worker->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth(),
            'can_witness_controlled' => true,
        ]);
        $presenceClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        Shift::factory()->create([
            'client_id' => $presenceClient->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress',
            'started_by' => $this->witness->id,
            'created_by' => $this->witness->id,
        ]);
    }

    public function test_shift_medication_snapshot_requires_a_shift_id(): void
    {
        $this->actingAs($this->worker)
            ->getJson('/emar/handovers/shift-medications')
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_shift_medication_snapshot_returns_window_scoped_picture(): void
    {
        $shift = $this->makeShift();

        // A PRN dose given inside the shift window, with no effectiveness review yet —
        // exercises the snapshot's direct prn-given / reviews-outstanding queries.
        $prn = ClientMedication::factory()->create([
            'client_id' => $this->client->id, 'name' => 'Lorazepam', 'dosage' => '1mg',
            'is_prn' => true, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
        ]);
        ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $prn->id,
            'status' => 'given',
            'administered_at' => now()->subHours(2),
            'administered_by' => $this->worker->id,
        ]);

        $response = $this->actingAs($this->worker)
            ->getJson('/emar/handovers/shift-medications?shift_id='.$shift->id)
            ->assertOk()
            ->assertJsonStructure([
                'snapshot' => [
                    'window' => ['start', 'end'],
                    'counts' => ['due', 'given', 'missed', 'refused', 'cd_due', 'prn_given', 'reviews_outstanding', 'omissions'],
                    'due', 'alerts', 'generated_at',
                ],
            ]);

        $response->assertJsonPath('snapshot.counts.prn_given', 1);
        $response->assertJsonPath('snapshot.counts.reviews_outstanding', 1);
    }

    public function test_controlled_drug_count_is_persisted_on_store(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'CD register reconciled with the incoming worker.',
                'medications_due_text' => 'Morphine 5mg — due 20:00',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'cd_notes' => 'All controlled-drug counts matched.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $cd = $handover->cd_verification;

        $this->assertIsArray($cd);
        $this->assertSame('verified', $cd['result']);
        $this->assertSame($this->witness->id, (int) $cd['witness_id']);
        $this->assertSame($this->witness->name, $cd['witness_name']);
        $this->assertSame($this->worker->id, (int) $cd['verified_by']);
        $this->assertNotNull($cd['verified_at']);
        $this->assertSame('Morphine 5mg — due 20:00', $handover->medications_due[0]['label']);

        $audit = AuditLog::query()
            ->where('action', 'shift.handover.cdVerification.created')
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->sole();
        $this->assertSame($this->witness->id, (int) data_get($audit->meta, 'witness_id'));
        $encodedAudit = json_encode($audit->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', strtolower($encodedAudit));
        $this->assertStringNotContainsString('credential', strtolower($encodedAudit));
        $this->assertSame(1, TimelineEvent::query()
            ->where('type', ShiftTimelineService::HANDOVER_CREATED_EVENT_TYPE)
            ->where('source_type', ShiftHandover::class)
            ->where('source_id', $handover->id)
            ->count());
    }

    public function test_operations_wizard_path_persists_and_replaces_governed_controlled_evidence(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->worker)
            ->post('/operations/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'Operations handover with a governed count.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'cd_notes' => 'Register and physical count matched.',
                'submit' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $handover = ShiftHandover::query()
            ->where('outgoing_shift_id', $shift->id)
            ->sole();
        $createdVerification = $handover->cd_verification;
        $createdAttestation = data_get($createdVerification, 'witness_attestation');
        $witnessProfile = HrEmployeeProfile::query()->where('user_id', $this->witness->id)->sole();
        $competency = MedicationCompetencyAssessment::query()->where('user_id', $this->witness->id)->sole();
        $presenceShift = Shift::query()->where('user_id', $this->witness->id)->sole();
        $this->assertSame('verified', data_get($createdVerification, 'result'));
        $this->assertSame($this->witness->id, (int) data_get($createdVerification, 'witness_id'));
        $this->assertSame('medications.controlled.witness', data_get($createdAttestation, 'authority_permission'));
        $this->assertSame($witnessProfile->id, (int) data_get($createdAttestation, 'employment_profile_id'));
        $this->assertSame('valid', data_get($createdAttestation, 'competency_state'));
        $this->assertSame($competency->id, (int) data_get($createdAttestation, 'competency_assessment_id'));
        $this->assertSame('shift', data_get($createdAttestation, 'presence_source'));
        $this->assertSame($presenceShift->id, (int) data_get($createdAttestation, 'presence_record_id'));
        $this->assertSame(
            Carbon::parse((string) $presenceShift->getRawOriginal('starts_at'), config('app.timezone', 'UTC'))->toIso8601String(),
            data_get($createdAttestation, 'presence_started_at'),
        );
        $this->assertSame(
            Carbon::parse((string) $presenceShift->getRawOriginal('ends_at'), config('app.timezone', 'UTC'))->toIso8601String(),
            data_get($createdAttestation, 'presence_ends_at'),
        );
        $this->assertNotNull(data_get($createdAttestation, 'witnessed_at'));

        $createdAudit = AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.created')
            ->sole();
        $this->assertSame($createdAttestation, data_get($createdAudit->meta, 'witness_attestation'));
        $this->assertSame($createdAttestation, data_get($createdAudit->meta, 'replacement_verification.witness_attestation'));

        $replacementAt = now()->addMinute()->setMicrosecond(0);
        Carbon::setTestNow($replacementAt);
        try {
            $presence = HrAttendanceSession::query()->create([
                'user_id' => $this->witness->id,
                'shift_id' => $presenceShift->id,
                'site_id' => $this->site->id,
                'clock_in_at' => $replacementAt->copy()->subMinutes(5),
                'clock_out_at' => $replacementAt,
                'status' => 'closed',
                'source' => 'manual',
                'created_by' => $this->witness->id,
                'closed_by' => $this->witness->id,
            ]);

            $this->actingAs($this->worker)
                ->put("/operations/handovers/{$handover->id}", [
                    'handover_notes' => 'Operations handover with replacement evidence.',
                    'cd_result' => 'discrepancy',
                    'cd_witness_id' => $this->witness->id,
                    'cd_witness_credential' => 'password',
                    'cd_notes' => 'One tablet requires reconciliation.',
                    'version' => $handover->version,
                    'submit' => false,
                ])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        } finally {
            Carbon::setTestNow();
        }

        $replacement = $handover->fresh()->cd_verification;
        $replacementAttestation = data_get($replacement, 'witness_attestation');
        $this->assertSame('discrepancy', data_get($replacement, 'result'));
        $this->assertSame('One tablet requires reconciliation.', data_get($replacement, 'notes'));
        $this->assertSame('attendance_session', data_get($replacementAttestation, 'presence_source'));
        $this->assertSame($presence->id, (int) data_get($replacementAttestation, 'presence_record_id'));
        $this->assertSame(
            Carbon::parse((string) $presence->getRawOriginal('clock_in_at'), config('app.timezone', 'UTC'))->toIso8601String(),
            data_get($replacementAttestation, 'presence_started_at'),
        );
        $this->assertSame(
            Carbon::parse((string) $presence->getRawOriginal('clock_out_at'), config('app.timezone', 'UTC'))->toIso8601String(),
            data_get($replacementAttestation, 'presence_ends_at'),
        );
        $this->assertSame($witnessProfile->id, (int) data_get($replacementAttestation, 'employment_profile_id'));
        $this->assertSame($competency->id, (int) data_get($replacementAttestation, 'competency_assessment_id'));
        $this->assertNotSame($createdAttestation, $replacementAttestation);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.created')
            ->count());
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.replaced')
            ->count());
        $replacementAudit = AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.replaced')
            ->sole();
        $this->assertSame($createdVerification, data_get($replacementAudit->meta, 'previous_verification'));
        $this->assertSame($replacement, data_get($replacementAudit->meta, 'replacement_verification'));
        $this->assertSame($replacementAttestation, data_get($replacementAudit->meta, 'witness_attestation'));
        $encodedAudit = json_encode($replacementAudit->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', strtolower($encodedAudit));
        $this->assertStringNotContainsString('credential', strtolower($encodedAudit));
    }

    public function test_omitted_cd_fields_preserve_evidence_and_replacement_is_freshly_governed(): void
    {
        $shift = $this->makeShift();
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'Initial controlled-drug handover evidence.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'cd_notes' => 'Register and physical count matched.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole();
        $originalEvidence = $handover->cd_verification;

        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'Narrative changed without touching controlled-drug evidence.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();

        $this->assertSame($originalEvidence, $handover->fresh()->cd_verification);
        $this->assertSame(1, AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.created')
            ->count());

        $handover->refresh();
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'Attempted unsigned replacement.',
                'cd_result' => 'discrepancy',
                'cd_witness_id' => $this->witness->id,
                'cd_notes' => 'One tablet requires reconciliation.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertSessionHasErrors('cd_witness_credential');
        $this->assertSame($originalEvidence, $handover->fresh()->cd_verification);

        $handover->refresh();
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'Governed replacement evidence.',
                'cd_result' => 'discrepancy',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'cd_notes' => 'One tablet requires reconciliation.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();

        $replacement = $handover->fresh()->cd_verification;
        $this->assertSame('discrepancy', $replacement['result']);
        $this->assertSame('One tablet requires reconciliation.', $replacement['notes']);
        $this->assertSame($this->witness->id, (int) $replacement['witness_id']);
        $replacementAudit = AuditLog::query()
            ->where('auditable_type', ShiftHandover::class)
            ->where('auditable_id', $handover->id)
            ->where('action', 'shift.handover.cdVerification.replaced')
            ->sole();
        $encodedAudit = json_encode($replacementAudit->meta, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('password', strtolower($encodedAudit));
        $this->assertStringNotContainsString('credential', strtolower($encodedAudit));
    }

    public function test_controlled_drug_discrepancy_requires_nonblank_notes(): void
    {
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted blank discrepancy evidence.',
                'cd_result' => 'discrepancy',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'cd_notes' => '   ',
                'submit' => false,
            ])
            ->assertSessionHasErrors('cd_notes');

        $this->assertDatabaseCount('shift_handovers', 0);
    }

    public function test_controlled_drug_handover_verification_requires_both_exact_controlled_capabilities(): void
    {
        $viewOnly = $this->makeUser('support_worker', [
            'medications.view', 'handovers.create', 'shifts.update',
            'medications.controlled.view',
        ]);
        $this->denyPermissions($viewOnly, ['medications.controlled.record']);
        $recordOnly = $this->makeUser('support_worker', [
            'medications.view', 'handovers.create', 'shifts.update',
            'medications.controlled.record',
        ]);
        $this->denyPermissions($recordOnly, ['medications.controlled.view']);

        foreach ([$viewOnly, $recordOnly] as $actor) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => null,
            ]);
            $shift = $this->makeShift();
            $shift->forceFill(['user_id' => $actor->id])->saveQuietly();

            $path = $actor->is($recordOnly)
                ? '/operations/handovers'
                : '/emar/handovers';
            $this->actingAs($actor)
                ->post($path, [
                    'shift_id' => $shift->id,
                    'handover_notes' => 'Attempted medications-due write.',
                    'medications_due_text' => 'Morphine 5mg — due 20:00',
                    'submit' => false,
                ])
                ->assertNotFound();

            $this->actingAs($actor)
                ->post($path, [
                    'shift_id' => $shift->id,
                    'handover_notes' => 'Attempted CD verification.',
                    'cd_result' => 'verified',
                    'cd_witness_id' => $this->witness->id,
                    'cd_witness_credential' => 'password',
                    'submit' => false,
                ])
                ->assertNotFound();
        }

        $this->assertDatabaseCount('shift_handovers', 0);
    }

    public function test_medications_due_edit_requires_both_exact_controlled_capabilities(): void
    {
        $actors = [
            $this->makeUser('support_worker', [
                'medications.view', 'handovers.create', 'shifts.update',
                'medications.controlled.view',
            ]),
            $this->makeUser('support_worker', [
                'medications.view', 'handovers.create', 'shifts.update',
                'medications.controlled.record',
            ]),
        ];
        $this->denyPermissions($actors[0], ['medications.controlled.record']);
        $this->denyPermissions($actors[1], ['medications.controlled.view']);

        foreach ($actors as $index => $actor) {
            HrEmployeeProfile::factory()->create([
                'user_id' => $actor->id,
                'primary_site_id' => $this->site->id,
                'secondary_site_ids' => [],
                'is_active' => true,
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => null,
            ]);
            $shift = $this->makeShift();
            $shift->forceFill(['user_id' => $actor->id])->saveQuietly();
            $handover = ShiftHandover::factory()->draft()->create([
                'outgoing_shift_id' => $shift->id,
                'client_id' => $this->client->id,
                'outgoing_staff_id' => $actor->id,
                'handover_notes' => 'Original governed medication handover.',
                'medications_due' => [['label' => 'Original medication due']],
                'version' => 1,
            ]);

            $path = $index === 0
                ? "/emar/handovers/{$handover->id}"
                : "/operations/handovers/{$handover->id}";
            $this->actingAs($actor)
                ->put($path, [
                    'handover_notes' => 'Attempted medication rewrite.',
                    'medications_due_text' => '',
                    'version' => 1,
                    'submit' => false,
                ])
                ->assertNotFound();

            $this->assertSame(
                [['label' => 'Original medication due']],
                $handover->fresh()->medications_due,
            );
        }
    }

    public function test_controlled_drug_handover_witness_must_be_current_staff_at_the_client_site(): void
    {
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignWitness = $this->makeUser('support_worker', ['medications.controlled.witness']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignWitness->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted foreign witness.',
                'cd_result' => 'verified',
                'cd_witness_id' => $foreignWitness->id,
                'cd_witness_credential' => 'password',
                'submit' => false,
            ])
            ->assertNotFound();

        $this->actingAs($this->worker)
            ->post('/operations/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted missing witness.',
                'cd_result' => 'verified',
                'cd_witness_id' => (int) User::query()->max('id') + 1000,
                'cd_witness_credential' => 'password',
                'submit' => false,
            ])
            ->assertNotFound();

        $this->witness->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted stale witness.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'password',
                'submit' => false,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('shift_handovers', 0);
    }

    public function test_controlled_drug_handover_rejects_self_witness_and_bad_credentials(): void
    {
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted self witness.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->worker->id,
                'cd_witness_credential' => 'password',
                'submit' => false,
            ])
            ->assertSessionHasErrors('cd_witness_id');

        $this->actingAs($this->worker)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'handover_notes' => 'Attempted bad credential.',
                'cd_result' => 'verified',
                'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => 'wrong-handover-secret',
                'submit' => false,
            ])
            ->assertSessionHasErrors([
                'cd_witness_credential' => 'The witness credential could not be verified.',
            ]);

        $this->assertArrayNotHasKey('cd_witness_credential', session()->getOldInput());
        $this->assertStringNotContainsString(
            'wrong-handover-secret',
            json_encode(session()->all(), JSON_THROW_ON_ERROR),
        );

        $this->assertDatabaseCount('shift_handovers', 0);
    }

    public function test_controlled_drug_handover_witness_attempts_are_throttled_across_emar_and_operations(): void
    {
        $ipAddress = '203.0.113.42';
        $rateLimitKey = implode(':', [
            'shift-handover',
            'cd-witness',
            $this->worker->id,
            $this->witness->id,
            $this->site->id,
            hash('sha256', $ipAddress),
        ]);
        RateLimiter::clear($rateLimitKey);
        Log::spy();

        try {
            foreach (range(1, 5) as $attempt) {
                $path = $attempt % 2 === 0
                    ? '/operations/handovers'
                    : '/emar/handovers';

                $this->actingAs($this->worker)
                    ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                    ->post($path, [
                        'shift_id' => $this->makeShift()->id,
                        'handover_notes' => "Rejected witness attempt {$attempt}.",
                        'cd_result' => 'verified',
                        'cd_witness_id' => $this->witness->id,
                        'cd_witness_credential' => 'wrong-handover-secret',
                        'submit' => false,
                    ])
                    ->assertSessionHasErrors([
                        'cd_witness_credential' => 'The witness credential could not be verified.',
                    ]);
            }

            $this->actingAs($this->worker)
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->post('/emar/handovers', [
                    'shift_id' => $this->makeShift()->id,
                    'handover_notes' => 'Correct credential remains blocked inside the decay window.',
                    'cd_result' => 'verified',
                    'cd_witness_id' => $this->witness->id,
                    'cd_witness_credential' => 'password',
                    'submit' => false,
                ])
                ->assertSessionHasErrors([
                    'cd_witness_credential' => 'The witness credential could not be verified.',
                ]);

            $this->assertSame(5, RateLimiter::attempts($rateLimitKey));

            $this->witness->hrEmployeeProfile()->update([
                'end_date' => now()->subDay()->toDateString(),
            ]);
            $this->actingAs($this->worker)
                ->withServerVariables(['REMOTE_ADDR' => $ipAddress])
                ->post('/operations/handovers', [
                    'shift_id' => $this->makeShift()->id,
                    'handover_notes' => 'Throttled witness is no longer eligible.',
                    'cd_result' => 'verified',
                    'cd_witness_id' => $this->witness->id,
                    'cd_witness_credential' => 'password',
                    'submit' => false,
                ])
                ->assertNotFound();

            $this->assertSame(5, RateLimiter::attempts($rateLimitKey));
            $this->assertDatabaseCount('shift_handovers', 0);
            Log::shouldHaveReceived('warning')
                ->atLeast()
                ->once()
                ->withArgs(function (string $message, array $context): bool {
                    $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                    return $message === 'Shift handover controlled-drug witness credential rejected.'
                        && ($context['security_event'] ?? null) === 'shift_handover_cd_witness_credential_rejected'
                        && ! array_key_exists('credential', $context)
                        && ! array_key_exists('password', $context)
                        && ! str_contains($encoded, 'wrong-handover-secret');
                });
        } finally {
            RateLimiter::clear($rateLimitKey);
        }
    }

    public function test_incoming_shift_assignment_is_canonical_and_rechecked_before_submit_and_acknowledge(): void
    {
        $replacement = $this->makeUser('support_worker', ['shifts.update']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $replacement->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $outgoingShift = $this->makeShift();
        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => now()->addMinutes(15),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
            'created_by' => $this->worker->id,
        ]);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift->id,
                'incoming_staff_id' => $this->worker->id,
                'handover_notes' => 'Attempted forged incoming assignment.',
                'submit' => false,
            ])
            ->assertSessionHasErrors('incoming_staff_id');
        $this->assertDatabaseCount('shift_handovers', 0);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $outgoingShift->id,
                'incoming_shift_id' => $incomingShift->id,
                'incoming_staff_id' => $this->witness->id,
                'handover_notes' => 'Canonical incoming assignment.',
                'submit' => false,
            ])
            ->assertRedirect();
        $handover = ShiftHandover::query()->where('outgoing_shift_id', $outgoingShift->id)->sole();

        $incomingShift->forceFill(['user_id' => $replacement->id])->save();
        try {
            app(ShiftHandoverService::class)->submit($handover->fresh(), $this->worker);
            $this->fail('A stale incoming worker snapshot must not survive Shift reassignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('incoming_staff_id', $exception->errors());
        }
        $this->assertSame(ShiftHandoverService::STATUS_DRAFT, $handover->fresh()->status);

        $handover->refresh();
        app(ShiftHandoverService::class)->save(
            $outgoingShift->fresh(),
            $this->worker,
            [
                'incoming_shift_id' => $incomingShift->id,
                'incoming_staff_id' => $replacement->id,
                'handover_notes' => 'Incoming reassignment re-resolved before submission.',
                'expected_version' => $handover->version,
                'submit' => true,
            ],
        );

        $handover->refresh();
        $this->assertSame($replacement->id, (int) $handover->incoming_staff_id);
        $this->actingAs($this->witness)
            ->post("/emar/handovers/{$handover->id}/acknowledge")
            ->assertForbidden();
        $this->actingAs($replacement)
            ->post("/emar/handovers/{$handover->id}/acknowledge")
            ->assertRedirect();
        $this->assertSame(ShiftHandoverService::STATUS_ACKNOWLEDGED, $handover->fresh()->status);
        $this->assertSame($replacement->id, (int) $handover->fresh()->acknowledged_by);
    }

    public function test_incoming_shift_binding_is_explicit_and_adapters_preserve_omitted_versus_null(): void
    {
        $outgoingShift = $this->makeShift();
        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => $outgoingShift->ends_at,
            'ends_at' => $outgoingShift->ends_at->copy()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->worker->id,
        ]);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $outgoingShift->id,
                'handover_notes' => 'Leave this handover open until the incoming Shift is reviewed.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->where('outgoing_shift_id', $outgoingShift->id)->sole();
        $this->assertNull($handover->incoming_shift_id);
        $this->assertNull($handover->incoming_staff_id);

        $this->actingAs($this->worker)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'An omitted incoming assignment remains open.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();
        $handover->refresh();
        $this->assertNull($handover->incoming_shift_id);
        $this->assertNull($handover->incoming_staff_id);

        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Bind the reviewed incoming Shift.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();
        $handover->refresh();
        $this->assertSame($incomingShift->id, (int) $handover->incoming_shift_id);
        $this->assertSame($this->witness->id, (int) $handover->incoming_staff_id);

        $this->actingAs($this->worker)
            ->put("/operations/handovers/{$handover->id}", [
                'handover_notes' => 'Omitting a reviewed assignment preserves it.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();
        $handover->refresh();
        $this->assertSame($incomingShift->id, (int) $handover->incoming_shift_id);
        $this->assertSame($this->witness->id, (int) $handover->incoming_staff_id);

        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'incoming_shift_id' => null,
                'handover_notes' => 'Explicitly return this handover to an open draft.',
                'version' => $handover->version,
                'submit' => false,
            ])
            ->assertRedirect();
        $handover->refresh();
        $this->assertNull($handover->incoming_shift_id);
        $this->assertNull($handover->incoming_staff_id);

        $this->actingAs($this->worker)
            ->post("/emar/handovers/{$handover->id}/submit")
            ->assertSessionHasErrors([
                'incoming_shift_id' => 'Assign the exact incoming Shift before submitting this handover.',
            ]);
        $this->assertSame(ShiftHandoverService::STATUS_DRAFT, $handover->fresh()->status);
    }

    public function test_incoming_staff_effective_dates_use_the_worker_timezone(): void
    {
        config()->set('app.timezone', 'UTC');
        config()->set('app.worker_timezone', 'Pacific/Auckland');
        $this->travelTo(Carbon::parse('2026-08-28 12:30:00', 'UTC'));

        try {
            $this->witness->hrEmployeeProfile()->update([
                'start_date' => '2026-08-29',
                'end_date' => '2026-08-29',
            ]);
            $outgoingShift = $this->makeShift();
            $incomingShift = Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $this->witness->id,
                'starts_at' => $outgoingShift->ends_at,
                'ends_at' => $outgoingShift->ends_at->copy()->addHours(8),
                'status' => 'scheduled',
                'created_by' => $this->worker->id,
            ]);

            $result = app(ShiftHandoverService::class)->save($outgoingShift, $this->worker, [
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'The incoming worker is current on the local worker date.',
                'submit' => true,
            ]);

            $this->assertSame(ShiftHandoverService::STATUS_SUBMITTED, $result['handover']->status);
            $this->assertSame($this->witness->id, (int) $result['handover']->incoming_staff_id);
        } finally {
            $this->travelBack();
        }
    }

    public function test_direct_incoming_worker_must_be_current_staff_at_the_client_site(): void
    {
        $foreignSite = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $foreignWorker = $this->makeUser('support_worker', ['shifts.update']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignWorker->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $this->makeShift()->id,
                'incoming_staff_id' => $foreignWorker->id,
                'handover_notes' => 'Attempted foreign incoming worker.',
                'submit' => false,
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('shift_handovers', 0);
    }

    public function test_database_enforces_one_handover_identity_per_outgoing_shift(): void
    {
        $shift = $this->makeShift();
        ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->worker->id,
            'incoming_staff_id' => null,
        ]);

        $this->expectException(QueryException::class);
        ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->worker->id,
            'incoming_staff_id' => null,
        ]);
    }

    public function test_later_canonical_incoming_shift_does_not_rebind_an_unbounded_handover(): void
    {
        $outgoingShift = $this->makeShift();
        $legacyHandover = ShiftHandover::factory()->create([
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => null,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->worker->id,
            'incoming_staff_id' => $this->witness->id,
            'status' => ShiftHandoverService::STATUS_SUBMITTED,
            'submitted_at' => now()->subMinutes(10),
            'submitted_by' => $this->worker->id,
        ]);
        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->worker->id,
        ]);

        $requirement = app(ShiftHandoverService::class)->completionRequirement($outgoingShift->fresh());

        $this->assertTrue($requirement['requires_handover']);
        $this->assertSame($incomingShift->id, $requirement['matched_shift']?->id);
        $this->assertNull($requirement['matched_handover']);
        $this->assertNotNull($legacyHandover->fresh());
    }

    public function test_handover_waiver_audit_failure_rolls_back_waiver_and_timeline(): void
    {
        $outgoingShift = $this->makeShift();
        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => now(),
            'ends_at' => now()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->worker->id,
        ]);
        $requirement = app(ShiftHandoverService::class)->completionRequirement($outgoingShift->fresh());
        $this->assertSame($incomingShift->id, $requirement['matched_shift']?->id);

        AuditLog::creating(static function (AuditLog $audit): void {
            if ($audit->action === 'shift.handover.waived') {
                throw new \RuntimeException('Injected handover waiver audit failure.');
            }
        });

        try {
            DB::transaction(function () use ($outgoingShift, $requirement): void {
                app(ShiftHandoverService::class)->recordCompletionWaiver(
                    $outgoingShift,
                    $this->worker,
                    'Rollback this waiver.',
                    $requirement,
                );
            });
            $this->fail('The injected handover waiver audit failure did not escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected handover waiver audit failure.', $exception->getMessage());
        }

        $this->assertNull($outgoingShift->fresh()->handover_waiver_reason);
        $this->assertNull($outgoingShift->fresh()->handover_waived_at);
        $this->assertNull($outgoingShift->fresh()->handover_waived_by);
        $this->assertDatabaseMissing('timeline_events', [
            'type' => ShiftTimelineService::HANDOVER_WAIVED_EVENT_TYPE,
            'source_type' => Shift::class,
            'source_id' => $outgoingShift->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'shift.handover.waived',
            'auditable_id' => $outgoingShift->id,
        ]);
    }

    public function test_destroy_draft_rechecks_terminal_state_under_the_canonical_service_lock(): void
    {
        $shift = $this->makeShift();
        $incomingShift = Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->witness->id,
            'starts_at' => $shift->ends_at,
            'ends_at' => $shift->ends_at->copy()->addHours(8),
            'status' => 'scheduled',
            'created_by' => $this->worker->id,
        ]);
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'incoming_shift_id' => $incomingShift->id,
                'handover_notes' => 'Draft that may be deleted.',
                'submit' => false,
            ])
            ->assertRedirect();
        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole();
        $service = app(ShiftHandoverService::class);

        $service->submit($handover->fresh(), $this->worker);
        try {
            $service->destroyDraft($handover->fresh(), $this->worker);
            $this->fail('A submitted handover must not be deleted.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('shift_handovers', ['id' => $handover->id]);

        $deletableShift = $this->makeShift();
        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $deletableShift->id,
                'handover_notes' => 'Canonical draft deletion.',
                'submit' => false,
            ])
            ->assertRedirect();
        $deletable = ShiftHandover::query()->where('outgoing_shift_id', $deletableShift->id)->sole();
        $service->destroyDraft($deletable, $this->worker);
        $this->assertDatabaseMissing('shift_handovers', ['id' => $deletable->id]);
    }

    public function test_optimistic_version_blocks_a_stale_concurrent_edit(): void
    {
        $shift = $this->makeShift();

        $this->actingAs($this->worker)
            ->post('/emar/handovers', [
                'shift_id' => $shift->id,
                'handover_notes' => 'Initial draft.',
                'submit' => false,
            ])
            ->assertRedirect();

        $handover = ShiftHandover::query()->latest('id')->firstOrFail();
        $this->assertSame(1, (int) $handover->version);

        // Editing with the current version succeeds and bumps the token.
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'First edit.',
                'version' => 1,
                'submit' => false,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) $handover->fresh()->version);

        // A second editor still on version 1 is blocked, not silently overwritten.
        $this->actingAs($this->worker)
            ->put("/emar/handovers/{$handover->id}", [
                'handover_notes' => 'Stale edit that should be rejected.',
                'version' => 1,
                'submit' => false,
            ])
            ->assertSessionHasErrors('handover');

        $this->assertSame('First edit.', $handover->fresh()->handover_notes);
    }

    public function test_cd_required_is_stamped_when_the_client_has_a_controlled_drug(): void
    {
        // A client with no controlled meds is not CD-required (DB default false).
        $this->actingAs($this->worker)
            ->post('/emar/handovers', ['shift_id' => $this->makeShift()->id, 'handover_notes' => 'No CDs.', 'submit' => false])
            ->assertRedirect();
        $this->assertFalse((bool) ShiftHandover::query()->latest('id')->firstOrFail()->cd_required);

        // A second client that DOES have an active controlled medication → flagged.
        $cdClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'status' => 'active',
        ]);
        ClientMedication::factory()->create([
            'client_id' => $cdClient->id,
            'name' => 'Morphine',
            'controlled_drug' => true,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);

        $cdShift = Shift::factory()->create([
            'client_id' => $cdClient->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subMinutes(15),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $this->worker->id,
            'created_by' => $this->worker->id,
        ]);

        $this->actingAs($this->worker)
            ->post('/emar/handovers', ['shift_id' => $cdShift->id, 'handover_notes' => 'CDs present.', 'submit' => false])
            ->assertRedirect();

        $handover = ShiftHandover::query()->where('outgoing_shift_id', $cdShift->id)->firstOrFail();
        $this->assertTrue((bool) $handover->cd_required, 'cd_required should be true when the client has an active controlled drug');
    }

    public function test_presence_lock_blocks_a_second_editor_until_released(): void
    {
        $service = app(ShiftHandoverService::class);
        $shift = $this->makeShift();
        $manager = $this->makeUser('admin', ['shifts.manageAny']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
        $handover = ShiftHandover::factory()->draft()->create([
            'outgoing_shift_id' => $shift->id,
            'client_id' => $this->client->id,
            'outgoing_staff_id' => $this->worker->id,
        ]);

        // First worker takes the lock (null = acquired).
        $this->assertNull($service->acquireEditLock($handover->fresh(), $this->worker));

        // A related reader cannot clear a lock they never acquired.
        $service->releaseEditLock($handover->fresh(), $this->witness);
        $this->assertSame($this->worker->id, (int) $handover->fresh()->locked_by);

        // A second authorised editor is blocked and told who holds it.
        $this->assertSame($this->worker->name, $service->acquireEditLock($handover->fresh(), $manager));

        // Once the holder releases, the second worker can take it.
        $service->releaseEditLock($handover->fresh(), $this->worker);
        $this->assertNull($service->acquireEditLock($handover->fresh(), $manager));

        // The former holder cannot release the manager's new lock.
        $service->releaseEditLock($handover->fresh(), $this->worker);
        $this->assertSame($manager->id, (int) $handover->fresh()->locked_by);
    }

    protected function makeShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHours(4),
            'ends_at' => now()->subMinutes(15),
            'actual_starts_at' => now()->subHours(4),
            'status' => 'in_progress',
            'started_by' => $this->worker->id,
            'created_by' => $this->worker->id,
        ]);
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeUser(string $roleName, array $permissionKeys = []): User
    {
        $user = User::factory()->create(['role' => $roleName, 'approved_at' => now()]);

        $role = Role::query()->where('name', $roleName)->first();
        if ($role) {
            $user->roles()->syncWithoutDetaching([$role->id]);
        }

        if ($permissionKeys !== []) {
            $map = Permission::query()
                ->whereIn('key', $permissionKeys)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all();
            $user->permissionOverrides()->syncWithoutDetaching($map);
        }

        return $user;
    }

    /** @param array<int, string> $permissionKeys */
    private function denyPermissions(User $user, array $permissionKeys): void
    {
        $map = Permission::query()
            ->whereIn('key', $permissionKeys)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])
            ->all();
        $user->permissionOverrides()->syncWithoutDetaching($map);
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }
}
